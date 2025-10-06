<?php

namespace Payum\Paypal\Rest\Action;

use ArrayAccess;
use League\Uri\Http as HttpUri;
use League\Uri\UriModifier;
use PayPal\Api\Amount;
use PayPal\Api\Authorization;
use PayPal\Api\Capture as PaypalCapture;
use PayPal\Api\Payer;
use PayPal\Api\Payment as PaypalPayment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Rest\ApiContext;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Security\GenericTokenFactoryAwareInterface;
use Payum\Core\Security\GenericTokenFactoryAwareTrait;

class CaptureAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface, GenericTokenFactoryAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;
    use GenericTokenFactoryAwareTrait;

    public function __construct()
    {
        $this->apiClass = ApiContext::class;
    }

    public function execute($request): void
    {
        /** @var Capture $request */
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var ArrayAccess|PaypalPayment $model */
        $model = $request->getModel();

        $this->gateway->execute($httpRequest = new GetHttpRequest());

        if (isset($httpRequest->query['cancelled'])) {
            if ($model instanceof PaypalPayment) {
                $model->setState('cancelled');
            } else {
                $model['state'] = 'cancelled';
            }

            return;
        }

        if ($model instanceof PaypalPayment) {
            $payment = $model;
        } else {
            $payment = $this->captureArrayAccess($model, $request, $httpRequest);
        }

        if (
            ! isset($payment->state) &&
            isset($payment->payer->payment_method) &&
            'paypal' == $payment->payer->payment_method
        ) {
            $payment->create($this->api);

            if ($model instanceof ArrayAccess) {
                $model->replace($payment->toArray());
            }

            foreach ($payment->links as $link) {
                if ('approval_url' == $link->rel) {
                    throw new HttpRedirect($link->href);
                }
            }
        }

        if (
            ! isset($payment->state) &&
            isset($payment->payer->payment_method) &&
            'credit_card' == $payment->payer->payment_method
        ) {
            $payment->create($this->api);

            if ($model instanceof ArrayAccess) {
                $model->replace($payment->toArray());
            }
        }

        if (
            isset($payment->state) &&
            isset($payment->payer->payment_method) &&
            'paypal' == $payment->payer->payment_method
        ) {
            $this->gateway->execute($httpRequest = new GetHttpRequest());

            // Check if this is a capture request (vs authorization)
            $isCapture = $httpRequest->query['isFinalCapture'] ?? false;
            if ($isCapture) {
                // For capture: find the authorization and capture it
                $this->captureAuthorizedPayment($payment);
            } else {
                // For authorization: use PaymentExecution as before
                $execution = new PaymentExecution();
                $execution->payer_id = $httpRequest->query['PayerID'];
                $payment->execute($execution, $this->api);
            }

            if ($model instanceof ArrayAccess) {
                $model->replace($payment->toArray());
            }
        }
    }

    public function supports($request)
    {
        return $request instanceof Capture &&
            ($request->getModel() instanceof PaypalPayment || $request->getModel() instanceof ArrayAccess)
        ;
    }

    private function captureArrayAccess(ArrayAccess $model, Capture $request, GetHttpRequest $httpRequest): PaypalPayment
    {
        if (isset($model['id'])) {
            return PaypalPayment::get($model['id'], $this->api);
        }

        $payer = new Payer();
        $payer->setPaymentMethod('paypal');

        $amount = new Amount();
        $amount->setTotal($model['amount'] / 100);
        $amount->setCurrency($model['currency']);

        $transaction = new Transaction();
        $transaction->setAmount($amount);

        $redirectUrls = new RedirectUrls();
        $returnUrl = $this->tokenFactory->createCaptureToken(
            $request->getToken()->getGatewayName(),
            $request->getToken()->getDetails(),
            $request->getToken()->getAfterUrl()
        )->getTargetUrl();

        $cancelUri = HttpUri::createFromString($returnUrl);
        $redirectUrls->setReturnUrl($returnUrl)
            ->setCancelUrl((string) UriModifier::mergeQuery($cancelUri, 'cancelled=1'));

        $payment = new PaypalPayment();
        $payment->setIntent('authorize')
            ->setPayer($payer)
            ->setTransactions([$transaction])
            ->setRedirectUrls($redirectUrls);

        return $payment;
    }

    /**
     * Capture an authorized PayPal payment
     */
    private function captureAuthorizedPayment(PaypalPayment $payment): void
    {
        // Get the authorization from the payment
        $transactions = $payment->getTransactions();
        if (!$transactions || count($transactions) === 0) {
            return;
        }

        $transaction = $transactions[0];
        $relatedResources = $transaction->getRelatedResources();
        if (!$relatedResources || count($relatedResources) === 0) {
            return;
        }

        // Find the authorization in related resources
        $authorization = null;
        foreach ($relatedResources as $relatedResource) {
            if ($relatedResource->getAuthorization()) {
                $authorization = $relatedResource->getAuthorization();
                break;
            }
        }

        if (!$authorization) {
            return;
        }

        // Create capture object with the full authorized amount
        $capture = new PaypalCapture();
        $capture->setAmount($authorization->getAmount());
        $capture->getAmount()->setDetails(null); //providing details throws error
        $capture->setIsFinalCapture(true);

        // Capture the authorization
        try {
            $authorization->capture($capture, $this->api);
            
            // Update the payment state to reflect the capture
            $payment->setState('approved');
            
        } catch (\Exception $e) {
            // Log the error but don't throw to maintain backward compatibility
            error_log('PayPal capture failed: ' . $e->getMessage());
        }
    }
}
