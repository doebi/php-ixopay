<?php

namespace Ixopay\Client\Xml;
use Ixopay\Client\Data\Result\CreditcardData;
use Ixopay\Client\Data\Result\ResultData;
use Ixopay\Client\Exception\InvalidValueException;
use Ixopay\Client\Transaction\Error;
use Ixopay\Client\Transaction\Result;
use Ixopay\Client\Callback\Result as CallbackResult;

/**
 * Class Parser
 *
 * @package Ixopay\Client\Xml
 */
class Parser {

    /**
     * @param string $xml
     * @return Result
     * @throws InvalidValueException
     */
    public function parseResult($xml) {
        $result = new Result();

        $document = new \DOMDocument('1.0', 'utf-8');
        $document->loadXML($xml);

        $root = $document->getElementsByTagNameNS('http://www.ixolit.com/IxoPay/V2/Result', 'result');
        if ($root->length < 0) {
            throw new InvalidValueException('XML does not contain a root "result" element');
        }
        $root = $root->item(0);

        foreach ($root->childNodes as $child) {
            /**
             * @var \DOMNode $child
             */
            switch ($child->localName) {
                case 'success':
                    $result->setSuccess($child->nodeValue ? true : false);
                    break;
                case 'referenceId':
                case 'registrationId':
                case 'redirectUrl':
                case 'htmlContent':
                case 'paymentDescriptor':
                    $result->{'set'.ucfirst($child->localName)}($child->nodeValue);
                    break;
                case 'returnType':
                    $result->setReturnType($this->parseReturnType($child));
                    break;
                case 'returnData':
                    $result->setReturnData($this->parseReturnData($child));
                    break;
                case 'errors':
                    $result->setErrors($this->parseErrors($child));
                    break;
                case 'extraData':
                    list($key, $value) = $this->parseExtraData($child);
                    $result->addExtraData($key, $value);
                    break;
                default:
                    if ($child->nodeName != '#text' && $child->localName != 'exception') {
                        throw new InvalidValueException('Unexpected element "' . $child->nodeName . '"');
                    }
                    break;
            }
        }

        return $result;

    }

    /**
     * @param string $xml
     * @return CallbackResult
     * @throws InvalidValueException
     */
    public function parseCallback($xml) {
        $result = new CallbackResult();

        $document = new \DOMDocument('1.0', 'utf-8');
        $document->loadXML($xml);

        $root = $document->getElementsByTagNameNS('http://www.ixolit.com/IxoPay/V2/Postback', 'postback');
        if ($root->length < 0) {
            throw new InvalidValueException('XML does not contain a root "postback" element');
        }
        $root = $root->item(0);

        foreach ($root->childNodes as $child) {
            /**
             * @var \DOMNode $child
             */
            switch ($child->localName) {
                case 'result':
                    $result->setResult($child->nodeValue);
                    break;
                case 'referenceId':
                    $result->setReferenceId($child->nodeValue);
                    break;
                case 'transactionId':
                    $result->setTransactionId($child->nodeValue);
                    break;
                case 'errors':
                    $result->setErrors($this->parseErrors($child));
                    break;
                case 'extraData':
                    list($key, $value) = $this->parseExtraData($child);
                    $result->addExtraData($key, $value);
                    break;
                default:
                    if ($child->nodeName != '#text') {
                        throw new InvalidValueException('Unexpected element "' . $child->nodeName . '"');
                    }
                    break;
            }
        }

        return $result;
    }

    /**
     * @param \DOMNode $node
     * @return string
     * @throws InvalidValueException
     */
    protected function parseReturnType(\DOMNode $node) {
        switch ($node->nodeValue) {
            case 'FINISHED':
                return Result::RETURN_TYPE_FINISHED;
                break;
            case 'REDIRECT':
                return Result::RETURN_TYPE_REDIRECT;
                break;
            case 'HTML':
                return Result::RETURN_TYPE_HTML;
                break;
            case 'PENDING':
                return Result::RETURN_TYPE_PENDING;
                break;
            case 'ERROR':
                return Result::RETURN_TYPE_ERROR;
                break;
            default:
                throw new InvalidValueException('Value "'.$node->nodeValue.'" is not allowed for "returnType"');
                break;
        }
    }

    /**
     * @param \DOMNode $node
     * @return ResultData|null
     * @throws InvalidValueException
     */
    protected function parseReturnData(\DOMNode $node) {
        $type = $node->attributes->getNamedItem('type');
        //dd($node->attributes->item(0));
        if (!$type) {
            return null;
        }

        if ($type->firstChild->nodeValue == 'creditcardData') {
            $node = $node->firstChild;
            while($node->nodeName == '#text') {
                $node = $node->nextSibling;
            }
            if ($node->localName != 'creditcardData') {
                throw new InvalidValueException('Expecting element named "creditcardData"');
            }
            $cc = new CreditcardData();
            foreach ($node->childNodes as $child) {
                /**
                 * @var \DOMNode $child
                 */
                switch ($child->localName) {
                    case 'type':
                    case 'firstName':
                    case 'lastName':
                    case 'country':
                    case 'cardHolder':
                    case 'firstSixDigits':
                    case 'lastFourDigits':
                        $cc->{'set'.ucfirst($child->localName)}($child->nodeValue);
                        break;
                    case 'expiryMonth':
                    case 'expiryYear':
                    $cc->{'set'.ucfirst($child->localName)}((int)$child->nodeValue);
                        break;
                    default:
                        break;
                }
            }
            return $cc;
        }
        return null;
    }

    /**
     * @param \DOMNode $node
     * @return Error[]
     * @throws InvalidValueException
     */
    protected function parseErrors(\DOMNode $node) {
        $errors = array();

        foreach ($node->childNodes as $child) {
            /**
             * @var \DOMNode $child
             */
            if ($child->nodeName == '#text') {
                continue;
            }
            if ($child->localName != 'error') {
                throw new InvalidValueException('Expecting element named "error"');
            }
            $message = $code = $adapterMessage = $adapterCode = null;
            foreach ($child->childNodes as $c) {
                /**
                 * @var \DOMNode $c
                 */
                switch ($c->localName) {
                    case 'message':
                        $message = $c->nodeValue;
                        break;
                    case 'code':
                        $code = $c->nodeValue;
                        break;
                    case 'adapterMessage':
                        $adapterMessage = $c->nodeValue;
                        break;
                    case 'adapterCode':
                        $adapterCode = $c->nodeValue;
                        break;
                    default:
                        break;
                }
            }

            $error = new Error($message, $code, $adapterMessage, $adapterCode);
            $errors[] = $error;

        }
        return $errors;
    }

    /**
     * @param \DOMNode $node
     * @return array
     */
    protected function parseExtraData(\DOMNode $node) {
        $key = $node->attributes->getNamedItem('key')->nodeValue;
        $value = $node->nodeValue;

        return array($key, $value);
    }

}