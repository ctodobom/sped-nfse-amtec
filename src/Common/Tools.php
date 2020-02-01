<?php

namespace NFePHP\NFSeAmtec\Common;

/**
 * Auxiar Tools Class for comunications with NFSe webserver in Nacional Standard
 *
 * @category  NFePHP
 * @package   NFePHP\NFSeAmtec
 * @copyright NFePHP Copyright (c) 2020
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @author    Roberto L. Machado <linux.rlm at gmail dot com>
 * @link      http://github.com/nfephp-org/sped-nfse-amtec for the canonical source repository
 */

use NFePHP\Common\Certificate;
use NFePHP\NFSeAmtec\RpsInterface;
use NFePHP\Common\DOMImproved as Dom;
use NFePHP\NFSeAmtec\Common\Signer;
use NFePHP\NFSeAmtec\Common\Soap\SoapInterface;
use NFePHP\NFSeAmtec\Common\Soap\SoapCurl;

class Tools
{
    public $lastRequest;
    
    protected $config;
    protected $prestador;
    protected $certificate;
    protected $wsobj;
    protected $soap;
    protected $environment;
        
    
    /**
     * Constructor
     * @param string $config
     * @param Certificate $cert
     */
    public function __construct($config, Certificate $cert)
    {
        $this->config = \Safe\json_decode($config);
        $this->certificate = $cert;
        $this->buildPrestadorTag();
        $this->wsobj = $this->loadWsobj($this->config->cmun);
        $this->environment = 'producao';
        if ($this->config->tpamb === 1) {
            $this->environment = 'producao';
        }
    }
    
    /**
     * load webservice parameters
     * @param string $cmun
     * @return object
     * @throws \Exception
     */
    protected function loadWsobj($cmun)
    {
        $path = realpath(__DIR__ . "/../../storage/urls_webservices.json");
        $urls = json_decode(file_get_contents($path), true);
        if (empty($urls[$cmun])) {
            throw new \Exception("Não localizado parâmetros para esse municipio.");
        }
        return (object) $urls[$cmun];
    }
    
    /**
     * SOAP communication dependency injection
     * @param SoapInterface $soap
     */
    public function loadSoapClass(SoapInterface $soap)
    {
        $this->soap = $soap;
    }
    
    /**
     * Build tag Prestador
     */
    protected function buildPrestadorTag()
    {
        $this->prestador = "<Prestador><CpfCnpj>";
        if (!empty($this->config->cnpj)) {
            $this->prestador .= "<Cnpj>" . $this->config->cnpj . "</Cnpj>";
        } else {
            $this->prestador .= "<Cpf>" . $this->config->cpf . "</Cpf>";
        }
        $this->prestador .= "</CpfCnpj>"
            . "<InscricaoMunicipal>" . $this->config->im . "</InscricaoMunicipal>"
            . "</Prestador>";
    }

    /**
     * Sign XML passing in content
     * @param string $content
     * @return string XML signed
     */
    public function sign($content)
    {
        $xml = Signer::sign(
            $this->certificate,
            $content,
            '',
            'Id',
            OPENSSL_ALGO_SHA1,
            [true, false, null, null]
        );
        $dom = new Dom('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($xml);
        return $dom->saveXML($dom->documentElement);
    }
    
    /**
     * Send message to webservice
     * @param string $message
     * @param string $operation
     * @return string XML response from webservice
     */
    public function send($message, $operation)
    {
        $action = "{$this->wsobj->soapns}$operation";
        $url = $this->wsobj->homologacao;
        if ($this->environment === 'producao') {
            $url = $this->wsobj->producao;
        }
        $request = $this->createSoapRequest($message, $operation);
        $this->lastRequest = $request;
        
        if (empty($this->soap)) {
            $this->soap = new SoapCurl($this->certificate);
        }
        $msgSize = strlen($request);
        $parameters = [
            "Content-Type: application/soap+xml; charset=utf-8",
            "SOAPAction: \"$action\"",
            "Content-length: $msgSize"
        ];
        $response = (string) $this->soap->send(
            $operation,
            $url,
            $action,
            $request,
            $parameters
        );
        return $this->extractContentFromResponse($response, $operation);
    }
    
    /**
     * Extract xml response from CDATA outputXML tag
     * @param string $response Return from webservice
     * @return string XML extracted from response
     */
    protected function extractContentFromResponse($response, $operation)
    {
        $dom = new Dom('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($response);
        $node = !empty($dom->getElementsByTagName("{$operation}Result")->item(0))
            ? $dom->getElementsByTagName("{$operation}Result")->item(0)
            : null;
        if (empty($node)) {
            return $response;
        } else {
            return $node->textContent;
        }
    }

    /**
     * Build SOAP request
     * @param string $message
     * @param string $operation
     * @return string XML SOAP request
     */
    protected function createSoapRequest($message, $operation)
    {
        
        $env = "<soap12:Envelope "
            . "xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" "
            . "xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\" "
            . "xmlns:soap12=\"http://www.w3.org/2003/05/soap-envelope\">"
            . "<soap12:Body>"
            . "<$operation xmlns=\"". $this->wsobj->soapns . "\">"
            . "<ArquivoXML></ArquivoXML>"
            . "</$operation>"
            . "</soap12:Body>"
            . "</soap12:Envelope>";
               
        $dom = new Dom('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($env);
        $node = $dom->getElementsByTagName('ArquivoXML')->item(0);
        $cdata = $dom->createCDATASection($message);
        $node->appendChild($cdata);
        return $dom->saveXML($dom->documentElement);
    }

    /**
     * Create tag Prestador and insert into RPS xml
     * @param RpsInterface $rps
     * @return string RPS XML (not signed)
     */
    protected function putPrestadorInRps(RpsInterface $rps)
    {
        $dom = new Dom('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($rps->render());
        $referenceNode = $dom->getElementsByTagName('Servico')->item(0);
        $node = $dom->createElement('Prestador');
        $CpfCnpj = $dom->createElement('CpfCnpj');
        if (!empty($this->config->cnpj)) {
            $dom->addChild(
                $CpfCnpj,
                "Cnpj",
                $this->config->cnpj,
                true
            );
        } else {
            $dom->addChild(
                $CpfCnpj,
                "Cpf",
                $this->config->cpf,
                true
            );
        }
        $node->appendChild($CpfCnpj);
        $dom->addChild(
            $node,
            "InscricaoMunicipal",
            $this->config->im,
            true
        );
        $dom->insertAfter($node, $referenceNode);
        
        if ($this->config->tpamb === 2) {
            $ref = $dom->getElementsByTagName('InfDeclaracaoPrestacaoServico')->item(0);
            $serie = $ref->getElementsByTagName('Serie')->item(0);
            $serie->nodeValue = 'TESTE';
        }
        return $dom->saveXML($dom->documentElement);
    }
}
