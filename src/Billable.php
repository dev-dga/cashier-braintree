<?php

namespace Laravel\Cashier;

use Exception;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Braintree\PaymentMethod;
use Braintree\PaypalAccount;
use InvalidArgumentException;
use Braintree\TransactionSearch;
use Illuminate\Support\Collection;
use Braintree\Customer as BraintreeCustomer;
use Braintree\Transaction as BraintreeTransaction;
use Braintree\Subscription as BraintreeSubscription;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait Billable
{
    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param  int  $amount
     * @param  array  $options
     * @return \Braintree\Transaction
     */
    public function charge($amount, array $options = [])
    {
        $customer = $this->asBraintreeCustomer();

        $response = BraintreeTransaction::sale(array_merge([
            'amount' => $amount * (1 + ($this->taxPercentage() / 100)),
            'paymentMethodToken' => $customer->paymentMethods[0]->token,
            'options' => [
                'submitForSettlement' => true,
            ],
            'recurring' => true,
        ], $options));

        if (! $response->success) {
            throw new Exception('Braintree was unable to perform a charge: '.$response->message);
        }

        return $response;
    }

    /**
     * Invoice the customer for the given amount.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  array  $options
     * @return \Braintree\Transaction
     */
    public function invoiceFor($description, $amount, array $options = [])
    {
        return $this->charge($amount, array_merge($options, [
            'customFields' => [
                'description' => $description,
            ],
        ]));
    }

    /**
     * Begin creating a new subscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\Cashier\SubscriptionBuilder
     */
    public function newSubscription($subscription, $plan)
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the user is on trial.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function onTrial($subscription = 'default', $plan = null)
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription($subscription);

        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
               $subscription->braintree_plan === $plan;
    }

    /**
     * Determine if the user is on a "generic" trial at the user level.
     *
     * @return bool
     */
    public function onGenericTrial()
    {
        return $this->trial_ends_at && Carbon::now()->lt($this->trial_ends_at);
    }

    /**
     * Determine if the user has a given subscription.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function subscribed($subscription = 'default', $plan = null)
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
               $subscription->braintree_plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     *
     * @param  string  $subscription
     * @return \Laravel\Cashier\Subscription|null
     */
    public function subscription($subscription = 'default')
    {
        return $this->subscriptions->sortByDesc(function ($value) {
            return $value->created_at->getTimestamp();
        })
        ->first(function ($key, $value) use ($subscription) {
            return $value->name === $subscription;
        });
    }

    /**
     * Get all of the subscriptions for the user.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class)->orderBy('created_at', 'desc');
    }

    /**
     * Find an invoice by ID.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice|null
     */
    public function findInvoice($id)
    {
        try {
            return new Invoice($this, BraintreeTransaction::find($id));
        } catch (Exception $e) {
            //
        }
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @param  string  $id
     * @return \Laravel\Cashier\Invoice
     */
    public function findInvoiceOrFail($id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException;
        } else {
            return $invoice;
        }
    }

    /**
     * Create an invoice download Response.
     *
     * @param  string  $id
     * @param  array   $data
     * @param  string  $storagePath
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function downloadInvoice($id, array $data, $storagePath = null)
    {
        return $this->findInvoiceOrFail($id)->download($data, $storagePath);
    }

    /**
     * Get a collection of the entity's invoices.
     *
     * @param  bool  $includePending
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoices($includePending = false, $parameters = [])
    {
        $invoices = [];

        $customer = $this->asBraintreeCustomer();

        $parameters = array_merge([
            TransactionSearch::customerId()->is($customer->id),
            TransactionSearch::createdAt()->between(
                Carbon::today()->subYears(2)->format('m/d/Y H:s'),
                Carbon::tomorrow()->format('m/d/Y H:s')
            ),
        ], $parameters);

        $transactions = BraintreeTransaction::search($parameters);

        // Here we will loop through the Braintree invoices and create our own custom Invoice
        // instance that gets more helper methods and is generally more convenient to work
        // work than the plain Braintree objects are. Then, we'll return the full array.
        if (! is_null($transactions)) {
            foreach ($transactions as $transaction) {
                if ($transaction->status == BraintreeTransaction::SETTLED || $includePending) {
                    $invoices[] = new Invoice($this, $transaction);
                }
            }
        }

        return new Collection($invoices);
    }

    /**
     * Get an array of the entity's invoices.
     *
     * @param  array  $parameters
     * @return \Illuminate\Support\Collection
     */
    public function invoicesIncludingPending(array $parameters = [])
    {
        return $this->invoices(true, $parameters);
    }

    /**
     * Update customer's credit card.
     *
     * @param  string  $token
     * @return void
     */
    public function updateCard($token)
    {
        $customer = $this->asBraintreeCustomer();

        $response = PaymentMethod::create([
            'customerId' => $customer->id,
            'paymentMethodNonce' => $token,
            'options' => [
                'makeDefault' => true,
                'verifyCard' => true,
            ],
        ]);

        if (! $response->success) {
            throw new Exception('Braintree was unable to create a payment method: '.$response->message);
        }

        $paypalAccount = $response->paymentMethod instanceof PaypalAccount;

        $this->forceFill([
            'paypal_email' => $paypalAccount ? $response->paymentMethod->email : null,
            'card_brand' => $paypalAccount ? null : $response->paymentMethod->cardType,
            'card_last_four' => $paypalAccount ? null : $response->paymentMethod->last4,
        ])->save();

        $this->updateSubscriptionsToPaymentMethod(
            $response->paymentMethod->token
        );
    }

    /**
     * Update the payment method token for all of the user's subscriptions.
     *
     * @param  string  $token
     * @return void
     */
    protected function updateSubscriptionsToPaymentMethod($token)
    {
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->active()) {
                BraintreeSubscription::update($subscription->braintree_id, [
                    'paymentMethodToken' => $token,
                ]);
            }
        }
    }

    /**
     * Apply a coupon to the billable entity.
     *
     * @param  string  $coupon
     * @param  string $subscription
     * @param  bool  $removeOthers
     * @return void
     */
    public function applyCoupon($coupon, $subscription = 'default', $removeOthers = false)
    {
        $subscription = $this->subscription($subscription);

        if (! $subscription) {
            throw new InvalidArgumentException("Unable to apply coupon. Subscription does not exist.");
        }

        $subscription->applyCoupon($coupon, $removeOthers);
    }

    /**
     * Determine if the user is actively subscribed to one of the given plans.
     *
     * @param  array|string  $plans
     * @param  string  $subscription
     * @return bool
     */
    public function subscribedToPlan($plans, $subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ((array) $plans as $plan) {
            if ($subscription->braintree_plan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param  string  $plan
     * @return bool
     */
    public function onPlan($plan)
    {
        return ! is_null($this->subscriptions->first(function ($key, $value) use ($plan) {
            return $value->braintree_plan === $plan;
        }));
    }

    /**
     * Create a Braintree customer for the given user.
     *
     * @param  string  $token
     * @param  array  $options
     * @return \Braintree\Customer
     */
    public function createAsBraintreeCustomer($token, array $options = [])
    {
        $response = BraintreeCustomer::create(
            array_replace_recursive($options, [
                'firstName' => Arr::get(explode(' ', $this->name), 0),
                'lastName' => Arr::get(explode(' ', $this->name), 1),
                'email' => $this->email,
                'paymentMethodNonce' => $token,
                'creditCard' => [
                    'options' => [
                        'verifyCard' => true,
                    ]
                ],
            ])
        );

        if (! $response->success) {
            throw new Exception('Unable to create Braintree customer: '.$response->message);
        }

        $paymentMethod = $response->customer->paymentMethods[0];

        $paypalAccount = $paymentMethod instanceof PaypalAccount;

        $this->forceFill([
            'braintree_id' => $response->customer->id,
            'paypal_email' => $paypalAccount ? $paymentMethod->email : null,
            'card_brand' => ! $paypalAccount ? $paymentMethod->cardType : null,
            'card_last_four' => ! $paypalAccount ? $paymentMethod->last4 : null,
        ])->save();

        return $response->customer;
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage()
    {
        return 0;
    }

    /**
     * Get the Braintree customer for the user.
     *
     * @return \Braintree\Customer
     */
    public function asBraintreeCustomer()
    {
        return BraintreeCustomer::find($this->braintree_id);
    }

    /**
     * Determine if the entity has a Braintree customer ID.
     *
     * @return bool
     */
    public function hasBraintreeId()
    {
        return ! is_null($this->braintree_id);
    }
}
