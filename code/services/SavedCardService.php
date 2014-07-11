<?php
use Omnipay\Common\CreditCard;

/**
 * Wrapper for create/update/deleteCard methods on omnipay gateway.
 *
 * @package omnipay
 */
class SavedCardService extends PaymentService {

    /**
     * Attempt to save a new credit card.
     *
     * @param  array $data returnUrl/cancelUrl + customer creditcard and billing/shipping details.
     * 	Some keys (e.g. "amount") are overwritten with data from the associated {@link $payment}.
     *  If this array is constructed from user data (e.g. a form submission), please take care
     *  to whitelist accepted fields, in order to ensure sensitive gateway parameters like "freeShipping" can't be set.
     *  If using {@link Form->getData()}, only fields which exist in the form are returned,
     *  effectively whitelisting against arbitrary user input.
     * @return ResponseInterface omnipay's response class, specific to the chosen gateway.
     */
    public function createCard($data = array()) {
        if ($this->payment->Status !== "Created") {
            return null; //could be handled better? send payment response?
        }
        if (!$this->payment->isInDB()) {
            $this->payment->write();
        }

        // if they didn't give us a name, create one from the masked number
        if (empty($data['name'])) {
            $data['name'] = preg_replace('/[^0-9]/', '', $data['number']);               // normalize out dashes and spaced
            $data['name'] = preg_replace('/[0-9]/', '*', $data['name']);                 // replace numbers
            $data['name'] = substr($data['name'], 0, -4) . substr($data['number'], -4);  // swap in the last 4 digits
        }

        $message = $this->createMessage('CreateCardRequest');
        $message->write();

        $request = $this->oGateway()->createCard(array_merge(
            $data,
            array(
                'card' => $this->getCreditCard($data),
                'clientIp' => isset($data['clientIp']) ? $data['clientIp'] : null,
            )
        ));
        $this->logToFile($request->getParameters(), "CreateCardRequest_post");

        $gatewayresponse = $this->createGatewayResponse();
        try {
            $response = $this->response = $request->send();
            $gatewayresponse->setOmnipayResponse($response);

            //update payment model
            if ($response->isSuccessful()) {
                //successful payment
                $this->createMessage('CreateCardResponse', $response);
                $gatewayresponse->setMessage("Card created successfully");

                // create the saved card
                $card = new SavedCreditCard(array(
                    'CardReference' => $response->getCardReference(),
                    'LastFourDigits' => substr($data['number'], -4),
                    'Name' => $data['name'],
                    'UserID' => Member::currentUserID(),
                ));

                $card->write();

                $this->payment->SavedCardID = $card->ID;
                $this->payment->write();
            } else {
                //handle error
                $this->createMessage('CreateCardError', $response);
                $gatewayresponse->setMessage(
                    "Error (".$response->getCode()."): ".$response->getMessage()
                );
            }
        } catch (Omnipay\Common\Exception\OmnipayException $e) {
            $this->createMessage('CreateCardError', $e);
            $gatewayresponse->setMessage($e->getMessage());
        }

        // not sure if this is needed
        $gatewayresponse->setRedirectURL($this->getRedirectURL());

        return $gatewayresponse;
    }


    public function updateCard(SavedCreditCard $card, $data = array()) {
        // TODO
    }

    public function deleteCard(SavedCreditCard $card) {
        // TODO
    }

    /**
     * @param array $data
     * @return \Omnipay\Common\CreditCard
     */
    protected function getCreditCard($data) {
        return new CreditCard($data);
    }


}