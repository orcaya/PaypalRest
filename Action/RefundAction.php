<?php

namespace Payum\Paypal\Rest\Action;

use ArrayAccess;
use PayPal\Api\Amount;
use PayPal\Api\Capture;
use PayPal\Api\DetailedRefund;
use PayPal\Api\Payment as PaypalPayment;
use PayPal\Api\Refund as PaypalRefund;
use PayPal\Api\Sale;
use PayPal\Rest\ApiContext;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Refund;

class RefundAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = ApiContext::class;
    }

    public function execute($request): void
    {
        /** @var Refund $request */
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var ArrayAccess|PaypalPayment $model */
        $model = $request->getModel();

        if ($model instanceof PaypalPayment) {
            $payment = $model;
        } else {
            // If model is ArrayAccess, try to get the payment by ID
            if (isset($model['id'])) {
                $payment = PaypalPayment::get($model['id'], $this->api);
            } else {
                throw new \InvalidArgumentException('Payment ID is required for refund');
            }
        }

        // Process refund based on payment state and available transactions
        if (isset($payment->transactions) && !empty($payment->transactions)) {
            $transaction = $payment->transactions[0];
            
            // Determine refund amount
            $refundAmount = null;
            if ($model instanceof ArrayAccess && isset($model['refund_amount'])) {
                $refundAmount = new Amount();
                $refundAmount->setTotal($model['refund_amount']);
                $refundAmount->setCurrency($model['currency'] ?? 'EUR');
            }

            // Check if payment has a sale (direct payment)
            if (isset($transaction->related_resources)) {
                foreach ($transaction->related_resources as $relatedResource) {
                    // Handle sale refund (direct payment)
                    if (isset($relatedResource->sale)) {
                        $sale = $relatedResource->sale;
                        $refund = $this->refundSale($sale, $refundAmount);
                        
                        if ($model instanceof ArrayAccess) {
                            $model['refund_id'] = $model['refund_id'] ? $model['refund_id'] . ',' . $refund->getId() : $refund->getId();
                            $model['refund_state'] = $refund->getState();
                            $model['refund_amount'] = $refund->getTotalRefundedAmount()->getValue();
                        }
                        return;
                    }
                    
                    // Handle capture refund (authorized and captured payment)
                    if (isset($relatedResource->capture)) {
                        $capture = $relatedResource->capture;
                        $refund = $this->refundCapture($capture, $refundAmount);
                        
                        if ($model instanceof ArrayAccess) {
                            $model['refund_id'] = $model['refund_id'] ? $model['refund_id'] . ',' . $refund->getId() : $refund->getId();
                            $model['refund_state'] = $refund->getState();
                            $model['refund_amount'] = $refund->getTotalRefundedAmount()->getValue();
                        }
                        return;
                    }
                }
            }
        }

        throw new \RuntimeException('Unable to find a refundable transaction in the payment');
    }

    public function supports($request): bool
    {
        return $request instanceof Refund &&
            ($request->getModel() instanceof PaypalPayment || $request->getModel() instanceof ArrayAccess);
    }

    private function refundSale(Sale $sale, ?Amount $amount = null): DetailedRefund
    {
        $refundRequest = new PaypalRefund();
        
        if ($amount !== null) {
            $refundRequest->setAmount($amount);
        }

        return $sale->refundSale($refundRequest, $this->api);
    }

    private function refundCapture(Capture $capture, ?Amount $amount = null): DetailedRefund
    {
        $refundRequest = new PaypalRefund();
        
        if ($amount !== null) {
            $refundRequest->setAmount($amount);
        }

        return $capture->refundCapturedPayment($refundRequest, $this->api);
    }
} 