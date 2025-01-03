<?php

// Bootstrap the Module, Composer Autoload etc.
require_once(__DIR__ . '/app/bootstrap.php');

use App\RegistrarModule;
use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Results\ResultsList;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// Pass all Module Calls through to the RegistrarModule Implementation
// to keep this file just a basic interface for whmcs.

function InterNetX_getConfigArray()
{

    return RegistrarModule::call('getConfigArray');
}

function InterNetX_RegisterDomain($params)
{

    return RegistrarModule::call('RegisterDomain', $params);
    // return RegistrarModule::RegisterDomain($params);
}

function InterNetX_TransferDomain($params)
{

    return RegistrarModule::call('TransferDomain', $params);
}

function InterNetX_RenewDomain($params)
{

    return RegistrarModule::call('RenewDomain', $params);
}

function InterNetX_GetNameservers($params)
{

    return RegistrarModule::call('GetNameservers', $params);
}

function InterNetX_SaveNameservers($params)
{

    return RegistrarModule::call('SaveNameservers', $params);
}

function InterNetX_GetDNS($params)
{

    return RegistrarModule::call('GetDNS', $params);
}

function InterNetX_SaveDNS($params)
{

    return RegistrarModule::call('SaveDNS', $params);
}

function InterNetX_GetContactDetails($params)
{

    return RegistrarModule::call('GetContactDetails', $params);
}

function InterNetX_SaveContactDetails($params)
{

    return RegistrarModule::call('SaveContactDetails', $params);
}

function InterNetX_GetEPPCode($params)
{

    return RegistrarModule::call('GetEPPCode', $params);
}

function InterNetX_Sync($params)
{

    return RegistrarModule::call('Sync', $params);
}

function InterNetX_TransferSync($params)
{

    return RegistrarModule::call('TransferSync', $params);
}

function InterNetX_IDProtectToggle($params)
{

    return RegistrarModule::call('IDProtectToggle', $params);
}

function InterNetX_GetEmailForwarding($params)
{

    return RegistrarModule::call('GetEmailForwarding', $params);
}

function InterNetX_SaveEmailForwarding($params)
{

    return RegistrarModule::call('SaveEmailForwarding', $params);
}

function InterNetX_CheckAvailability($params)
{

    return RegistrarModule::call('CheckAvailability', $params);
}

function InterNetX_GetDomainSuggestions($params)
{

    return RegistrarModule::call('GetDomainSuggestions', $params);
}

function InterNetX_AdminCustomButtonArray()
{

    return RegistrarModule::call('AdminCustomButtonArray');
}

function InterNetX_TransferOutNack($params)
{

    return RegistrarModule::call('TransferOutNack', $params);
}

function InterNetX_TransferOutAck($params)
{

    return RegistrarModule::call('TransferOutAck', $params);
}

function InterNetX_ClientAreaCustomButtonArray($params)
{

    return RegistrarModule::call('ClientAreaCustomButtonArray');
}

function InterNetX_DomainForwarding($params)
{

    return RegistrarModule::call('DomainForwarding', $params);
}

function InterNetX_ChangeOwner($params)
{

    return RegistrarModule::call('ChangeOwner', $params);
}

function InterNetX_GetTldPricing(array $params)
{

    $dd_username = $params['serverXMLUsername'];
    $dd_password = $params['serverXMLPassword'];
    $dd_context  = $params['serverContext'];

    $dd_url = 'https://api.autodns.com/v1/document/price_list.xml';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $dd_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$dd_username:$dd_password");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/xml',
        'Content-Type: application/xml',
        'X-Context: ' . $dd_context
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        return ['error' => 'Curl error: ' . curl_error($ch)];
    }

    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $requestInfo = curl_getinfo($ch);
    curl_close($ch);

    if (($httpCode === 200 || $httpCode === 406) && strpos($response, '<?xml') !== false) {
        $xml     = simplexml_load_string($response);
        $results = new ResultsList();

        foreach ($xml->prices->domain as $domain) {
            $domainLabel = '.' . (string) $domain['label'];
            $prices      = [
                'create'   => 0.0,
                'renew'    => 0.0,
                'transfer' => 0.0,
                'restore'  => 0.0,
            ];
            $currency    = 'EUR';

            foreach ($domain->businessCase as $case) {
                $caseLabel = (string) $case['label'];
                $price     = (float) $case->price['amount'];

                if (isset($prices[$caseLabel])) {
                    $prices[$caseLabel] = $price;
                }
            }

            $item = (new ImportItem())
                ->setExtension($domainLabel)
                ->setMinYears(1)
                ->setRegisterPrice($prices['create'])
                ->setRenewPrice($prices['renew'])
                ->setTransferPrice($prices['transfer'])
                ->setRedemptionFeePrice($prices['restore'])
                ->setCurrency($currency);

            $results[] = $item;
        }

        return $results;
    }

    return ['error' => 'Error retrieving price list. HTTP Code: ' . $httpCode . '<br>Request Info: <pre>' . print_r($requestInfo, true) . '</pre><br>Response: ' . htmlspecialchars($response)];
}
