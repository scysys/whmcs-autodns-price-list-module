<?php

namespace App;

use DateTime;
use Exception;
use App\Helpers\Config;

use App\Helpers\WhmcsBridge;
use Domainrobot\Domainrobot;
use Domainrobot\Model\Query;
use Domainrobot\Model\Domain;
use Domainrobot\Model\Contact;
use Domainrobot\Model\Redirect;
use Domainrobot\Model\NameServer;
use Domainrobot\Model\QueryFilter;
use Domainrobot\Lib\DomainrobotAuth;
use App\Providers\WhoisProxyProvider;
use Domainrobot\Model\TransferAnswer;

use Domainrobot\Lib\DomainrobotHeaders;
use Domainrobot\Model\DomainCancelation;
use Domainrobot\Lib\DomainrobotException;
use Domainrobot\Model\ContactItExtensions;
use App\Contracts\RegistrarModuleInterface;
use Domainrobot\Model\ContactTypeConstants;

use WHMCS\Domains\DomainLookup\ResultsList;
use App\Exceptions\RegistrarModuleException;
use Domainrobot\Model\RedirectModeConstants;
use Domainrobot\Model\RedirectTypeConstants;
use WHMCS\Domains\DomainLookup\SearchResult;
use Domainrobot\Model\ExecutionTypeConstants;
use Domainrobot\Model\RegistryStatusConstants;
use function PHPUnit\Framework\throwException;
use Domainrobot\Model\CancelationTypeConstants;
use App\Providers\WhmcsModuleLogProvider as Logger;
use Illuminate\Database\Capsule\Manager as Capsule;

class RegistrarModule implements RegistrarModuleInterface
{
    const LOGGERREPLACEVARS = ['sandboxServerContext', 'sandboxServerPassword', 'sandboxServerUsername', 'serverPassword', 'serverUsername', 'Username'];
    const LOGTHESEFUNCTIONCALLS = ['RegisterDomain', 'TransferDomain', 'RenewDomain', 'GetNameservers', 'SaveNameservers', 'GetDNS', 'SaveDNS', 'GetContactDetails', 'SaveContactDetails', 'GetEPPCode', 'Sync', 'TransferSync', 'IDProtectToggle', 'GetEmailForwarding', 'SaveEmailForwarding', 'CheckAvailability', 'GetDomainSuggestions', 'TransferOutNack', 'TransferOutAck', 'DomainForwarding', 'ChangeOwner'];



    private static function logWithParams($action, $params, $responseData, $processedData = '', $replaceVars = []){
        $fullReplaceVars = $replaceVars;

        foreach (self::LOGGERREPLACEVARS as $replaceKey) {
            // Get the Param Values for ReplaceKey and add to String that should not show up
            // added strlen > 1 to make sure other boolean values dont get replaced
            if ($params[$replaceKey] && strlen((string) $params[$replaceKey]) > 1){
                $fullReplaceVars[] = $params[$replaceKey];
            }

            // Also fully Remove Keys from params and original
            // if (isset($params[$replaceKey])){
            //      unset($params[$replaceKey]);
            // }

            // if (isset($params['original']) && isset($params['original'][$replaceKey])){
            //      unset($params['original'][$replaceKey]);
            // }

        }
        return Logger::log($action, $params, $responseData, $processedData, $fullReplaceVars);
    }

    /*
     * wrapper for all function to provide meaningfull error Responses as handled by whmcs
     */
    public static function call($functionName, $params = false)
    {
        try {
            // WhmcsBridge::saveParamsLog($functionName, $params);
            $response = call_user_func('self::' . $functionName, $params);

            // In Debug mode + Extended Debug Mode log all function calls that are in the List self::LOGTHESEFUNCTIONCALLS
            if (isset($params['debug']) && $params['debug'] && isset($params['debugallcalls']) && $params['debugallcalls'] && in_array($functionName, self::LOGTHESEFUNCTIONCALLS)){
                self::logWithParams($functionName, $params, $response);
            }
            return $response;
        } catch (RegistrarModuleException $th) {
            if ($params['debug']) {
                self::logWithParams($functionName, $params, $th->getMessage());
            }
            return WhmcsBridge::errorResponse($th->getMessage());
        } catch (DomainrobotException $th) {
            //TODO: Move output to Module log
            // public static function log($action, $requestString, $responseData, $processedData = '', $replaceVars = [])
            $errorString = "[" . $th->getStatusCode() . "] " . json_encode($th->getError(), JSON_PRETTY_PRINT);

            if ($params['debug']) {
                self::logWithParams($functionName, $params, $errorString);
            }

            return WhmcsBridge::errorResponse($errorString);
        } catch (\Throwable $th) {
            if ($params['debug']) {
                self::logWithParams($functionName, $params, $th);
            }
            return WhmcsBridge::errorResponse($th->getMessage());
        }
    }

    private static function getApi($params)
    {
        // Custom Headers to use with all API Requests
        $headers =  [
            DomainrobotHeaders::DOMAINROBOT_USER_AGENT => Config::userAgent(),
        ];

        // Use Sandbox Mode (Testmode)
        if ($params['sandboxMode'] == 'on') {
            WhmcsBridge::checkRequiredParametersOrFail(['sandboxServerUsername', 'sandboxServerPassword', 'sandboxServerContext'], $params);

            $domainrobot = new Domainrobot([
                "url" => "https://api.demo.autodns.com/v1",
                "auth" => new DomainrobotAuth([
                    "user"     => $params['sandboxServerUsername'],
                    "password" => $params['sandboxServerPassword'],
                    "context"  => $params['sandboxServerContext']
                ])
            ]);
            // Live Mode
        } else {
            WhmcsBridge::checkRequiredParametersOrFail(['serverUsername', 'serverPassword', 'serverContext'], $params);
            $domainrobot = new Domainrobot([
                // server Host can be manually set but default to the production Endpoint
                "url" => $params['serverHost'] ? $params['serverHost'] : "https://api.autodns.com/v1",
                "auth" => new DomainrobotAuth([
                    "user"     => $params['serverUsername'],
                    "password" => $params['serverPassword'],
                    "context"  => $params['serverContext']
                ])
            ]);
        }

        return $domainrobot;
    }

    public static function getConfigArray()
    {
        $adminEmails = Capsule::table('tbladmins')->select('email')->orderBy('email', 'asc')->pluck('email')->toArray();

        $configArray = array(
            "FriendlyName" => array(
                "Type" => "System",
                "Value" => "InterNetX",
            ),
            "Description" => array(
                "Type" => "System",
                "Value" => "Get your free Reseller Account here: <a href=\"https://www.internetx.com\" target=\"_blank\">https://www.internetx.com</a>.",
            ),
            "serverUsername" => array(
                'FriendlyName' => 'Username',
                "Type" => "text",
                "Size" => "20",
                "Description" => "Enter your username here.",
            ),
            "serverPassword" => array(
                'FriendlyName' => 'Password',
                "Type" => "password",
                "Size" => "20",
                "Description" => "Enter your password here.",
            ),
            "serverXMLUsername" => array(
                'FriendlyName' => 'XML Pricelist: Username',
                "Type" => "text",
                "Size" => "50",
                "Description" => "Enter your XML Pricelist username here.",
            ),
            "serverXMLPassword" => array(
                'FriendlyName' => 'XML Pricelist: Password',
                "Type" => "password",
                "Size" => "50",
                "Description" => "Enter your XML Pricelist password here.",
            ),
            "serverContext" => array(
                'FriendlyName' => 'Context',
                "Type" => "text",
                "Size" => "20",
                "Description" => "Enter your context here.",
            ),
            'serverHost' => array(
                'FriendlyName' => 'Server',
                'Type' => 'text',
                'Size' => '20',
                "Description" => "If left empty https://api.autodns.com/v1 will be used by default. Provide your API url if you use something different."
            ),
            'nameServers' => array(
                'FriendlyName' => 'Default Nameservers',
                "Type" => "textarea",
                "Rows" => "3",
                "Cols" => "18",
                "Default" => "",
                "Description" => "Provide your Default Nameservers (for Zone) Eg. ns1.demo.autodns2.de",
            ),
            "sandboxServerUsername" => array(
                'FriendlyName' => 'Sandbox Username',
                "Type" => "text",
                "Size" => "20",
                "Description" => "Enter your sandbox username here.",
            ),
            "sandboxServerPassword" => array(
                'FriendlyName' => 'Sandbox Password',
                "Type" => "password",
                "Size" => "20",
                "Description" => "Enter your sandbox password here.",
            ),
            "sandboxServerContext" => array(
                'FriendlyName' => 'Sandbox Context',
                "Type" => "text",
                "Size" => "20",
                "Description" => "Enter your sandbox context here.",
            ),
            'sandboxNameServers' => array(
                'FriendlyName' => 'Sandbox Default Nameservers',
                "Type" => "textarea",
                "Rows" => "3",
                "Cols" => "18",
                "Default" => "ns1.demo.autodns2.de\nns2.demo.autodns2.de",
                "Description" => "Provide your Default Nameservers (for Zone) Eg. ns1.demo.autodns2.de",
            ),
            'replyTo' => array(
                'FriendlyName' => 'Reply To',
                'Type' => 'dropdown',
                "Description" => "",
                "Options" => implode(",", $adminEmails),
                "Description" => "Contact address for support team in case of errors.",
            ),
            'domainMX' => array(
                'FriendlyName' => 'MX Record',
                "Type" => "text",
                "Size" => "20",
                "Description" => "MX record (mailserver). Enter the complete domain host name of the mailserver.",
            ),
            'domainIP' => array(
                'FriendlyName' => 'IP Address',
                "Type" => "text",
                "Size" => "20",
                "Description" => "IP address of the zone (A Record).",
            ),
            'autodelete' => array(
                'FriendlyName' => 'Auto Delete',
                'Type' => 'yesno'
            ),
            'sandboxMode' => array(
                'FriendlyName' => 'Test Mode',
                'Type' => 'yesno'
            ),
            'debug' => array(
                'FriendlyName' => 'Debug Mode',
                'Type' => 'yesno',
                "Description" => "Logs on \"Module Log\"",
            ),
            'debugallcalls' => array(
                'FriendlyName' => 'Extended Debug Mode',
                'Type' => 'yesno',
                "Description" => "Also log successfull calls on \"Module Log\"",
            ),
            'adminContact' => array(
                'FriendlyName' => 'Admin Contact',
                'Type' => 'yesno',
                "Description" => "Specify that the owner will be OwnerC and AdminC and reseller will be Billing/TechC",
            ),
            'hideDomainRedirect' => array(
                'FriendlyName' => 'Hide Domain Redirect',
                'Type' => 'yesno',
                "Description" => "Hide 'Domain Redirect' in the Client Area Side Bar",
            ),
            'hideOwnerChange' => array(
                'FriendlyName' => 'Hide Owner Change',
                'Type' => 'yesno',
                "Description" => "Hide 'Owner Change' in the Client Area Side Bar",
            ),
        );

        return $configArray;
    }

    public static function AdminCustomButtonArray()
    {
        $buttonArray = array(
            "Transfer Out ACK" => "transferOutAck",
            "Transfer Out NACK" => "transferOutNack",
        );
        return $buttonArray;
    }

    private static function prepareDomain($params, $domainrobot = false)
    {

        // Get API if not provided ny parameter
        if (!$domainrobot) {
            $domainrobot = self::getApi($params);
        }

        //Parse Contacts
        $contacts = WhmcsBridge::parseContacts($params);

        //Create Registrant and AdminContact
        $contacts['admin']      = $domainrobot->contact->create($contacts['admin']);
        $contacts['registrant'] = $domainrobot->contact->create($contacts['registrant']);
        // Keep only the 'id' everything else is not needed for the requests
        $contacts['admin']      = ['id' => $contacts['admin']['id']];
        $contacts['registrant'] = ['id' => $contacts['registrant']['id']];

        $nameservers = WhmcsBridge::parseNameservers($params);
        //Create Domain
        $domain = new Domain();
        $domain->setName(WhmcsBridge::parseDomain($params));
        $domain->setNameServers($nameservers);
        $domain->setOwnerc($contacts['registrant']);
        $domain->setAdminc($params['adminContact'] == 'on' ? $contacts['registrant'] : $contacts['admin']);
        $domain->setTechc($contacts['admin']);
        $domain->setZonec($contacts['admin']);
        $domain->setPrivacy($params['idprotection']);

        // Check for Premium Domain
        $isPremiumDomain = false;
        $premiumDomainPriceClass = null;

        try {
            $domainPremium = $domainrobot->domainPremium->info(WhmcsBridge::parseDomain($params));

            if (is_object($domainPremium) && $priceClass = $domainPremium->getPriceClass()) {
                $premiumDomainPriceClass = $priceClass;
                $isPremiumDomain = true;
            }
        } catch (DomainrobotException $th) {
            // domainPremium->info throws an exception if the domain is not Premium
            $isPremiumDomain = false;
            $premiumDomainPriceClass = null;
        }


        if ($isPremiumDomain) {
            if (WhmcsBridge::isPremiumDomainSetupOk($params)) {
                $domain->setPriceClass($premiumDomainPriceClass);
            } else {
                // Bail out on error here
                throw new RegistrarModuleException('Cannot register premium domain. Please make sure that your Premium Domains addon module is active and configured correctly.');
            }
        }
        // Add zone if nameservers are ours
        if (WhmcsBridge::containsRegistrarNameServer($nameservers)) {
            $zone = WhmcsBridge::parseDomainZone($params);
            // Initially i had to manually create the zone before the registration
            // This works now directly with the registration request, by using nameser->action = 'AUTO'
            // $createdZone = $domainrobot->zone->create($zone);
            $domain->setZone($zone);
        }
        return $domain;
    }

    private static function getDomainInfo($params)
    {
        $domainrobot = self::getApi($params);
        return $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
    }

    public static function RegisterDomain($params)
    {
        // return WhmcsBridge::errorResponse('RegisterDomain not implemented' . json_encode($params, JSON_PRETTY_PRINT) . '</pre>');
        //Get API
        $domainrobot = self::getApi($params);
        $domain = self::prepareDomain($params, $domainrobot);

        // TODO: Check if needed
        //$domain->setIgnoreWhois(true);
        $objectJob = $domainrobot->domain->create($domain);
        return $objectJob;
    }

    public static function TransferDomain($params)
    {
        $domainrobot = self::getApi($params);
        $domain = self::prepareDomain($params, $domainrobot);
        $domain->setAuthinfo($params['eppcode']);
        $domain->setConfirmOwnerConsent(1);
        $objectJob = $domainrobot->domain->transfer($domain);
        return true;
    }

    public static function RenewDomain($params)
    {
        // By Default, Accounts automatically renew Domains
        // but some Accounts have this disabled
        // So we HAVE TO manually renew every domain
        // if the Setting "autdelete" is 'on'
        // AutoDelete means "This is an Account with Auto Delete activated" as opposed to
        // "An Account that automatically renews domains"

        $doSendTheRenewRequest = false;

        // We need to manually renew the domain because the setting indiciates an account with autodelete on
        if (isset($params['autodelete']) && $params['autodelete'] == 'on') {
            $doSendTheRenewRequest = true;
        } else {
            // Autodelete is off so we normally don't have to send a renew request
            // Still the admin panel has the "renew" button that allows manual invocation of
            // this function. So we check for this and send out the renew anyway
            if (strpos($_SERVER['PHP_SELF'], 'clientsdomains.php') !== false) {
                $doSendTheRenewRequest = true;
            }
        }

        if ($doSendTheRenewRequest) {
            $domainrobot = self::getApi($params);
            $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
            $domain->setRemoveCancelation(true);
            $domainrobot->domain->renew($domain);
        }
        return true;
    }

    public static function GetNameservers($params)
    {

        $domainrobot = self::getApi($params);
        $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));

        if (isset($domain['nameServers']) && is_array($domain['nameServers'])) {
            $result = [];
            foreach ($domain['nameServers'] as $key => $ns) {
                $result['ns' . ($key + 1)] = $ns['name'];
            }
            return $result;
        }
    }

    public static function SaveNameservers($params)
    {
        $domainrobot = self::getApi($params);
        $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
        $domain->setNameServers(WhmcsBridge::parseNameservers($params));
        $domainrobot->domain->update($domain);
    }


    public static function GetDNS($params)
    {
        // return WhmcsBridge::errorResponse('GetDNS not implemented' . json_encode($params, JSON_PRETTY_PRINT) . '</pre>');
        $domainrobot = self::getApi($params);
        $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
        $zone = $domainrobot->zone->info(WhmcsBridge::parseDomain($params), $domain['nameServers'][0]['name']);

        $hostRecords = array();
        // Include mainIP as Resource Record
        // If it exists
        if ($main = $zone->getMain()) {
            if ($mainIP = $main->getAddress()) {
                $hostRecords[] = array(
                    "hostname" => '',    // eg. www
                    "type"     => 'A',    // eg. A
                    "address"  => $mainIP,   // eg. 10.0.0.1
                    "priority" => 'N/A',    // eg. 10 (N/A for non-MX records)
                );
                // Also add www-Entry if wwwInclude is set
                if ($zone->getWwwInclude()) {
                    $hostRecords[] = array(
                        "hostname" => 'www',    // eg. www
                        "type"     => 'A',    // eg. A
                        "address"  => $mainIP,   // eg. 10.0.0.1
                        "priority" => 'N/A',    // eg. 10 (N/A for non-MX records)
                    );
                }
            }
        }

        // Include all "real ResourceRecords"
        foreach ($zone['resourceRecords'] as $record) {
            $hostRecords[] = array(
                "hostname" => $record['name'],    // eg. www
                "type"     => $record['type'],    // eg. A
                "address"  => $record['value'],   // eg. 10.0.0.1
                "priority" => $record['pref'],    // eg. 10 (N/A for non-MX records)
            );
        }

        return $hostRecords;
    }

    public static function SaveDNS($params)
    {
        // return WhmcsBridge::errorResponse('SaveDNS not implemented' . json_encode($params, JSON_PRETTY_PRINT) . '</pre>');
        $domainrobot = self::getApi($params);

        // Get Current Zone From API
        $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
        $zone = $domainrobot->zone->info(WhmcsBridge::parseDomain($params), $domain['nameServers'][0]['name']);
        $newResourceRecords = WhmcsBridge::whmcs2domainrobotResourceRecords($params);
        $zone->setResourceRecords($newResourceRecords);

        // If our custom form was used to set the A Entry. Use it.
        // Else set MainIP to null
        if (isset($_REQUEST['zone']['main']['value'])) {
            $mainIP = $_REQUEST['zone']['main']['value'];
            $currentMain = $zone->getMain();
            $currentMain->setAddress($mainIP);
            $zone->setMain($currentMain);
        } else {
            $zone->setMain(null);
        }

        // If our custom form was used to use www_include use it
        // Else set wwwInclude to False
        if (isset($_REQUEST['zone']['www_include'])) {
            $www_include = (int) $_REQUEST['zone']['www_include'];
            $zone->setWwwInclude($www_include);
        } else {
            $zone->setWwwInclude(false);
        }
        $domainrobot->zone->update($zone);
    }

    public static function GetContactDetails($params)
    {
        // return WhmcsBridge::errorResponse('GetContactDetails not implemented'.json_encode($params, JSON_PRETTY_PRINT).'</pre>');
        $domainrobot = self::getApi($params);
        // Get Domain Info
        $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
        // Extract the Contacts
        $contacts = array(
            'Registrant' => $domain["ownerc"],
            'Admin'      => $domain["adminc"],
            'Tech'       => $domain["techc"],
            'Zone'       => $domain["zonec"],
        );
        // Remove Doubles
        $contacts = array_unique($contacts);
        // return $contacts;
        $whmcsContacts = [];
        foreach ($contacts as $key => $contact) {
            if (!$contact) continue;
            // Get full information from API and update Array value
            $contactDetails = $domainrobot->contact->info($contact['id']);

            // API returns an array of addresses up to n long
            // We just want to show two fields
            // so we combine everything > 2
            if (count($contactDetails["address"]) > 2) {
                $contactDetails["address"] = [
                    $contactDetails["address"][0],
                    implode(', ', array_slice($contactDetails["address"], 1))
                ];
            }

            $whmcsContacts[$key]['First Name']   = $contactDetails["fname"];
            $whmcsContacts[$key]['Last Name']    = $contactDetails["lname"];
            $whmcsContacts[$key]['Company Name'] = $contactDetails["organization"];
            $whmcsContacts[$key]['City']         = $contactDetails["city"];
            $whmcsContacts[$key]['Address 1']    = $contactDetails["address"][0];
            $whmcsContacts[$key]['Address 2']    = $contactDetails["address"][1];
            $whmcsContacts[$key]['State']        = $contactDetails["state"];
            $whmcsContacts[$key]['Postcode']     = $contactDetails["pcode"];
            $whmcsContacts[$key]['Country']      = $contactDetails["country"];
            $whmcsContacts[$key]['Phone Number'] = $contactDetails["phone"];
            $whmcsContacts[$key]['Email']        = $contactDetails["email"];
        }
        return $whmcsContacts;
    }

    public static function SaveContactDetails($params)
    {

        WhmcsBridge::checkRequiredParametersOrFail(['contactdetails'], $params);

        $domainrobot = self::getApi($params);
        // Get Domain Info
        $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
        // Extract the Contacts
        $contacts = array(
            'Registrant' => $domain["ownerc"],
            'Admin'      => $domain["adminc"],
            'Tech'       => $domain["techc"],
            'Zone'       => $domain["zonec"],
        );

        $whmcsContacts = $params['contactdetails'];


        foreach ($whmcsContacts as $key => $contact) {
            // Only Update if it exists
            if (!isset($contacts[$key])) {
                echo "Skipping $key\n";
                continue;
            }
            $liveContact = $domainrobot->contact->info($contacts[$key]['id']);
            // $liveContact["fname"] = ($whmcsContacts[$key]['First Name']);
            // $liveContact["lname"] = ($whmcsContacts[$key]['Last Name']);
            $liveContact["organization"] = ($whmcsContacts[$key]['Company Name']);
            $liveContact["city"] = ($whmcsContacts[$key]['City']);

            // Make Adresses Array
            $addresses = [$whmcsContacts[$key]['Address 1']];
            if ($whmcsContacts[$key]['Address 2']) {
                $addresses[] = $whmcsContacts[$key]['Address 2'];
            }
            $liveContact["address"] =  $addresses;
            $liveContact["state"] = ($whmcsContacts[$key]['State']);
            $liveContact["pcode"] = ($whmcsContacts[$key]['Postcode']);
            $liveContact["country"] = ($whmcsContacts[$key]['Country']);
            $liveContact["phone"] = ($whmcsContacts[$key]['Phone Number']);
            $liveContact["email"] = ($whmcsContacts[$key]['Email']);

            // Update the contact
            $r = $domainrobot->contact->update($liveContact);
        }
    }

    public static function GetEPPCode($params)
    {
        $domainrobot = self::getApi($params);

        // Get Domaininfo
        $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));

        // .de & .eu & .cz -> authinfo1Create
        if (strpos($params['tld'], 'de') !== false || strpos($params['tld'], 'eu') !== false || strpos($params['tld'], 'cz') !== false) {
            // If no AuthInfo Exists
            if (!$domain->getAuthinfo()){
                $domainrobot->domain->createAuthinfo1(WhmcsBridge::parseDomain($params));
            }
        } else {
            // SET Domain Status to Active if not already
            // For all tlds except the one above
            if ($domain->getRegistryStatus() != RegistryStatusConstants::ACTIVE) {
                $domain->setRegistryStatus(RegistryStatusConstants::ACTIVE);
                $domainrobot->domain->update($domain);
            }
        }

        $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
        return [
            'eppcode' => $domain->getAuthinfo()
        ];
    }

    public static function Sync($params)
    {
        $domainrobot = self::getApi($params);
        // Get Domaininfo
        try {
            $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
            $expiryDate =  new DateTime($domain->getPayable());
            $returnData =  [
                // Expiry = Payable
                'expirydate' => $expiryDate->format('Y-m-d')
            ];
            // Expired
            if ($expiryDate < new DateTime()) {
                $returnData['active'] = false;
                $returnData['cancelled'] = true;
            } else {
                // Active
                $returnData['active'] = true;
            }
            return $returnData;
        } catch (DomainrobotException $th) {
            $error = $th->getError();
            switch ($error['status']['code']) {
                case 'EF01012':
                    return ['expired' => true];
                    break;

                case 'E0105':
                    return ['cancelled' => true];
                    break;

                default:
                    return WhmcsBridge::errorResponse($error);
                    break;
            }

            return ['cancelled' => true];
        }
        // return WhmcsBridge::errorResponse('Sync not implemented'.json_encode($params, JSON_PRETTY_PRINT).'</pre>');
    }

    public static function TransferSync($params)
    {
        // Correct Return Values
        // Per Option
        // 1= Transfer has been completed
        // return array(
        //         'completed' => true, // Return as true upon successful completion of the transfer
        //         'expirydate' => (string)$result->expiration, // The expiry date of the domain
        //     );
        // 2) Transfer has Actually failed (As in we got a failed reason)
        // return array(
        //     'failed' => true,
        //     'reason' => $status
        // );
        // 3) Not sure what happened. No Error no Completion details
        // Can Resync later
        // return array(
        //     'completed' => false,
        //     'failed' => false
        // );
        // 4) Real Error (errorResponse()...)


        $domainrobot = self::getApi($params);
        // Get Domaininfo
        try {
            $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));
            $expiryDate =  new DateTime($domain->getPayable());

            $returnData =  [
                // Expiry = Payable
                'completed' => true,
                'expirydate' => $expiryDate->format('Y-m-d')
            ];
            return $returnData;
        } catch (DomainrobotException $th) {
            $error = $th->getError();
            return WhmcsBridge::errorResponse($error);
        }
    }

    public static function IDProtectToggle($params)
    {
        // return WhmcsBridge::errorResponse('GetEmailForwarding not implemented' . json_encode($params, JSON_PRETTY_PRINT) . '</pre>');
        $domainrobot = self::getApi($params);
        $domain = $domainrobot->domain->info(WhmcsBridge::parseDomain($params));

        //On
        if ($params['protectenable'] == 1) {
            $domain->setPrivacy(true);
            $domainrobot->domain->update($domain);
            //off
        } else {
            $domain->setPrivacy(false);
            $domainrobot->domain->update($domain);
        }
    }

    public static function GetEmailForwarding($params)
    {
        // return WhmcsBridge::errorResponse('GetEmailForwarding not implemented' . json_encode($params, JSON_PRETTY_PRINT) . '</pre>');
        $domainrobot = self::getApi($params);
        $domain = WhmcsBridge::parseDomain($params);
        // Get All E-Mail Redirects for domain from Server
        $query = new Query([
            'filters' => [ new QueryFilter([
                'key' => 'source',
                'value' => '*'.$domain,
                'operator' => 'LIKE'
            ])]
        ]);


        $redirectsOnServer = $domainrobot->redirect->list($query);
        $values = array();
        foreach ($redirectsOnServer as $key => $rd) {
            $mode = $rd['mode'];
            if ($rd['mode'] != RedirectModeConstants::SINGLE)
                continue;
            $source = current(explode("@", (string) $rd["source"]));
            $values[] = array(
                "recid" => $key,
                "prefix" => $source,
                "forwardto" => (string) $rd["target"],
            );
        }

        return $values;

    }

    public static function SaveEmailForwarding($params)
    {
        // return WhmcsBridge::errorResponse('SaveEmailForwarding not implemented' . json_encode($params, JSON_PRETTY_PRINT) . '</pre>');
        $domainrobot = self::getApi($params);
        $domain = WhmcsBridge::parseDomain($params);
        // Get Entries as Array in new
        $new = [];
        $prefix = $params['prefix'];
        $foward = $params['forwardto'];
        foreach ($params['prefix'] as $key => $value) {
            if (is_array($value)) {
                $new[] = array(
                    "prefix" => $value[0],
                    "forwardto" => $params['forwardto'][$key][0],
                );
            } else {
                $new[] = array(
                    "prefix" => $value,
                    "forwardto" => $params['forwardto'][$key],
                );
            }
        }

        foreach($new as $key => $redirect){

            // We can neither add or delete existing redirects if the prefix is not set
            if (!$redirect['prefix']){
                continue;
            }

            $source = $redirect['prefix']."@$domain";
            $target = $redirect['forwardto'];
            // Try to Delete Redirect
            // Throws error for new redirects so catch
            try {
                $response = $domainrobot->redirect->delete($source);
            } catch (\Throwable $th) {
                //throw $th;
            }

            if ($redirect['prefix'] && $redirect['forwardto']){
                $response = self::redirectCreate(
                    $domainrobot,
                    $source,
                    $target,
                    RedirectModeConstants::SINGLE,
                    RedirectTypeConstants::EMAIL
                );

            }

            // Add Redirect
        }


    }

    // TODO: check registrarmodule_DomainSuggestionOptions() {
    // https://github.com/WHMCS/sample-registrar-module/blob/master/modules/registrars/registrarmodule/registrarmodule.php
    public static function GetDomainSuggestions($params)
    {

        if ($params['isIdnDomain']) {
            $sld = empty($params['punyCodeSearchTerm']) ? strtolower($params['searchTerm']) : strtolower($params['punyCodeSearchTerm']);
        } else {
            $sld = strtolower($params['searchTerm']);
        }

        // Return only Available Domains by setting $includeUnavailableDomains = false
        return self::checkDomains($sld, $params, false);
    }


    public static function CheckAvailability($params)
    {
        return self::checkDomains($params['sld'], $params);
    }

    private static function checkDomains($sld, $params, $includeUnavailableDomains = true)
    {
        if (empty($params['tldsToInclude'])) {
            return false;
        }
        $tlds = $params['tldsToInclude'];

        // Initiate WHMCS Class ResultsList
        $results = new ResultsList();
        $checkResult = WhoisProxyProvider::whois($sld, $tlds, $params['debug']);

        foreach ($checkResult as $domain => $details) {
            $domainParts = explode('.', $domain);
            $sld = $domainParts[0];
            unset($domainParts[0]);
            $tld = implode('.', $domainParts);
            $searchResult = new SearchResult($sld, '.' . $tld);

            // Determine the appropriate status to return
            switch ($details['status']) {
                case 'free':
                    $status = SearchResult::STATUS_NOT_REGISTERED;
                    break;
                case 'assigned':
                    $status = SearchResult::STATUS_REGISTERED;
                    break;
                case 'reserved':
                    $status = SearchResult::STATUS_RESERVED;
                    break;
                case 'premium':
                    $status = SearchResult::STATUS_NOT_REGISTERED;
                    break;
                default:
                    $status = SearchResult::STATUS_TLD_NOT_SUPPORTED;
                    break;
            }
            $searchResult->setStatus($status);

            // Handle Premium Domains
            if ($details['premium']) {
                //check if premium domain Class Pricing is configured
                $isPremiumClassExists = Capsule::table('mod_InterNetXtblpremiumclasses')->select('id')->where('class_name', '=', $details['premiumClass'])->exists();

                if ($isPremiumClassExists && WhmcsBridge::isPremiumDomainSetupOk($params)) {
                    $className = $details['premiumClass'];
                    $premiumclassID = Capsule::table('mod_InterNetXtblpremiumclasses')->select('id')->where('class_name', '=', $className)->first();
                    $domainPricing = Capsule::select('SELECT * FROM mod_InterNetXtblpremiumpricing WHERE relid=' . $premiumclassID->id . ' AND currency=1');
                    $searchResult->setPremiumDomain(true);

                    foreach ($domainPricing as $pricing) {
                        if ($pricing->type == 'domainregister') {
                            $registerPrices = $pricing->{'1years'};
                            $currencyCodes = Capsule::table('tblcurrencies')->select('code')->where('id', '=', $pricing->currency)->first();
                        } elseif ($pricing->type == 'domaintransfer') {
                            $transferPrices = $pricing->{'1years'};
                        } elseif ($pricing->type == 'domainrenew') {
                            $renewPrices = $pricing->{'1years'};
                        }
                    }

                    if ($registerPrices == '0.00') {
                        $searchResult->setStatus(SearchResult::STATUS_RESERVED);
                    }

                    $searchResult->setPremiumCostPricing([
                        'register' => $registerPrices,
                        'renew' => $transferPrices,
                        'transfer' => $renewPrices,
                        'CurrencyCode' => $currencyCodes->code,
                        'skipMarkup' => true,
                    ]);
                } else { //END: if Premium Stuff Enabled Check
                    // Important!! Otherwise Premium Domains will be shown as available for the normal TLD price
                    // Although we do not have everything needed enabled
                    $searchResult->setStatus(SearchResult::STATUS_UNKNOWN);
                }
            } //END: if ($details['premium'])

            // How to handle Unavailable Domains?
            if ($searchResult->getStatus() != SearchResult::STATUS_NOT_REGISTERED) {
                // Defaults to include them But DomainSuggestions set includeUnavailableDomains to false
                if ($includeUnavailableDomains) {
                    $results->append($searchResult);
                }
            } else {
                $results->append($searchResult);
            }
        }

        if ($params['debug']) Logger::log('Method checkAvailability', print_r($_REQUEST, true), 'sld: ' . $sld . "\nTLDS: " . implode(', ', $tlds) . "\n" . print_r($checkResult, true));
        return $results;
    }


    /**
     * FUNCTION InterNetX_AdminCustomButtonArray
     * Transfer out domain ACK
     * @param type $params
     * @return string
     */
    private static function TransferOutRequest($params, $doTransfer = false)
    {
        $domainrobot = self::getApi($params);
        $answer = $doTransfer ? TransferAnswer::ACK : TransferAnswer::NACK;
        // The reason. Possible values are : 1 = Evidence of fraud / 2 = Current UDRP action / 3 = Court order / 4 = Identity dispute / 5 = No payment for previous registration period / 6 = Express written objection to the transfer from the transfer contact.
        // Set to "null" for ACK and to  "7" for NACK (The former Task used 7)
        $reason = $doTransfer ? null : 7;
        $objectJob = $domainrobot->transferOut->answer(WhmcsBridge::parseDomain($params), $answer, $reason);
        return $objectJob;
    }


    public static function TransferOutNack($params)
    {
        return self::TransferOutRequest($params, false);
    }

    public static function TransferOutAck($params)
    {
        return self::TransferOutRequest($params, true);
    }

    public static function ClientAreaCustomButtonArray($params)
    {

        $config = WhmcsBridge::getRegistarConfig();

        $customButtons = [];

        if ($config['hideDomainRedirect'] !== 'on') {
            $customButtons[\Lang::trans('Domain Redirect')] = 'DomainForwarding';
        }

        if ($config['hideOwnerChange'] !== 'on') {
            $customButtons[\Lang::trans('Owner Change')] = 'ChangeOwner';
        }

        return $customButtons;
    }

    private static function redirectCreate($domainrobot, $source, $target, $mode, $type, $title = null){
        $redirect = new Redirect();
        $redirect->setSource($source);
        $redirect->setTarget($target);
        $redirect->setMode($mode);
        $redirect->setType($type);
        if ($title){
            $redirect->setTitle($title);
        }
        $response = $domainrobot->redirect->create($redirect);
        return $response;
    }

    /**
     * FUNCTION InterNetX_domainForwarding
     * Manage domain forwarding in client area
     * Uses Template domainForwarding.tpl
     * Will Delete all existing redirect and recreate them
     * @param array $params
     * @return array $return
     */
    public static function DomainForwarding($params)
    {

        $domainrobot = self::getApi($params);
        $domain = WhmcsBridge::parseDomain($params);

        if (!empty($_POST)) {

            $domainForwarding = $_POST['domainForwarding'];
            foreach ($domainForwarding as $k => $df) {

                $oldSource = $df['id'];
                $source = $df['source'];
                $target = $df['target'];
                $mode = $df['mode'];
                $title = $df['title'];

                // delete when source or target are missing
                if (empty($source) || empty($target)) {
                    $response = $domainrobot->redirect->delete($oldSource);

                // Else Delete && create new
                // Deleted Update Step that included oldSource to trigger an update
                // if ->update function works it could be used again
                // } else if ($source == $oldSource) {
                } else {
                    $response = $domainrobot->redirect->delete($oldSource);
                    $response = self::redirectCreate(
                        $domainrobot, $source, $target, $mode, RedirectTypeConstants::DOMAIN, $title
                    );
                }

            }

            //adding new
            $newDomainForwarding = $_POST['newDomainForwarding'];
            if (!empty($newDomainForwarding['source']) && !empty($newDomainForwarding['target'])) {
                $response = self::redirectCreate(
                                        $domainrobot,
                                        $newDomainForwarding['source'],
                                        $newDomainForwarding['target'],
                                        $newDomainForwarding['mode'],
                                        "DOMAIN",
                                        $newDomainForwarding['title']
                                    );
            }
        }

        // Get All Redirects for domain from Server
        $query = new Query([
            'filters' => [ new QueryFilter([
                'key' => 'domain',
                'value' => $domain,
                'operator' => 'EQUAL'
            ])]
        ]);
        $redirectsOnServer = $domainrobot->redirect->list($query, ['title']);

        // Compile Redirects to Template Vars
        $redirects = [];
        foreach ($redirectsOnServer as $key => $rd) {
            // Ignore mode single
            if ($rd['mode'] == RedirectModeConstants::SINGLE)
                continue;

            $redirects[] = array(
                "source" => (string) $rd['source'],
                "target" => (string) $rd['target'],
                "mode" => (string) $rd['mode'],
                "title" => (string) $rd['title'],
            );
        }

        // Prepare TEmplate Vars
        $returnVars = [];
        $returnVars['domain'] = $domain;
        $returnVars['redirects'] = $redirects;

        return array(
            'templatefile' => 'domainForwarding',
            'breadcrumb' => ' > <a href="#">Domain Forwarding</a>',
            'vars' => $returnVars
        );
    }

    /**
     * FUNCTION InterNetX_changeOwner
     * Change owner domain in client area
     * @param array $params
     * @return array $return
     */
    public static function ChangeOwner($params)
    {


        $domainrobot = self::getApi($params);
        $domain = WhmcsBridge::parseDomain($params);

        if (!empty($_POST) && !empty($_POST['domainContacts']['ownerc']) ) {
            $contactId = $_POST['domainContacts']['ownerc'];

            // Get Details for Selected WHMCS Contact
            $whmcsContact = (array) Capsule::table('tblcontacts')->where('id', '=', $contactId)->where('userid', '=', (int) $_SESSION['uid'])->first();

            // Compile Params required to use whmcs2domainrobotContact($prefix, $params)
            $asWhmcsParams = [
                'tld' => $params['tld'],
                'additionalfields' => null,
                'companyname' => $whmcsContact['companyname'],
                'firstname' => $whmcsContact['firstname'],
                'lastname' => $whmcsContact['lastname'],
                'address1' => $whmcsContact['address1'],
                'address2' => $whmcsContact['address2'],
                'postcode' => $whmcsContact['postcode'],
                'city' => $whmcsContact['city'],
                'state' => $whmcsContact['state'],
                'country' => $whmcsContact['country'],
                'email' => $whmcsContact['email'],
                'phonenumber' => $whmcsContact['phonenumber'],
            ];

            // Get Additioanl Fields already saved for the Domain
            // And Add to params
            $fields = (array) Capsule::table('tbldomainsadditionalfields')->join('tbldomains', 'tbldomains.id', '=', 'tbldomainsadditionalfields.domainid')
                ->select('tbldomainsadditionalfields.*')->where('tbldomains.domain', '=', $domain)->orderBy('id', 'asc')->get();

            foreach ($fields as $field) {
                $asWhmcsParams['additionalfields'][$field->name] = $field->value;
            }
            // Create "new" ownerc Contact
            $domainRobotContact = WhmcsBridge::whmcs2domainrobotContact('', $asWhmcsParams);
            $ownerc = $domainrobot->contact->create($domainRobotContact);

            $liveDomain = $domainrobot->domain->info($domain);
            $liveDomain->setConfirmOwnerConsent(true);
            $liveDomain->setOwnerc($ownerc);

            if ($params['adminContact'] == 'on'){
                $liveDomain->setAdminc($ownerc);
            }

            $response = $domainrobot->domain->ownerChange($liveDomain);
            if ($response){
                $infoMsg = 'Owner Change Started';
            }
        }

        if (!empty($errors))
            $call['errors'] = $errors;

        if (isset($infoMsg) && $infoMsg)
            $call['info'] = $infoMsg;

        $call['domain'] = $domain;
        $call['contacts2'] = "<option value=''>Do not change Contact</option>";
        $query = Capsule::table('tblcontacts')->where('userid', '=', (int) $_SESSION['uid'])->get();
        foreach ($query as $row) {
            $call['contacts2'] .= "<option value='" . $row->id . "'>" . $row->firstname . ' ' . $row->lastname . '</option>';
        }

        return array(
            'templatefile' => 'changeOwner',
            'breadcrumb' => ' > <a href="#">Change Owner</a>',
            'vars' => $call
        );
    }

    public static function deleteDomain($domain, $execDate){
        // beim Delete Button in der Admin Area müsste eine Cancelation geschickt werden mit type "DELETE" und execution mit "DATE, EXPIRE, NOW" wobei nur das DATE mit dem date-picker gesetzt werden muss.
        // Ist wie ein Delete in AutoDNS den man auch zu Sofort(now), zu einem festen Datum(date) oder zum Ablauf(expire) setzen kann.
        $params = WhmcsBridge::getRegistarConfig();
        $domainrobot = self::getApi($params);
        $dc = new DomainCancelation();
        $dc->setDomain($domain);
        $dc->setType(CancelationTypeConstants::DELETE);



        $domainCancelation = $domainrobot->domainCancelation->create($dc);
        switch ($execDate) {
            case 'NOW':
                $dc->setExecution(ExecutionTypeConstants::NOW);
                break;

            case 'EXPIRE':
                $dc->setExecution(ExecutionTypeConstants::EXPIRE);
                break;

            // Handling a posted Expiration Date
            default:
                $dc->setExecution(ExecutionTypeConstants::DATE);
                $dc->setRegistryWhen(new DateTime($execDate));
                break;
        }
        // Delete Existing Cancelation request just in Case
        $domainrobot->domainCancelation->delete($domain);
        $domainCancelation = $domainrobot->domainCancelation->create($dc);
    }

    public static function domainCancelationCreate($domain){
        // beim Abschalten der Auto-Renew in der Client Area wird eine Cancelation mit Type "DELETE" und execution "EXPIRE" angelegt. Damit wird die Domain automatisch zur Fälligkeit gelöscht.
        // Sobald der Client den Auto-Renew wieder aktiviert muss diese Cancelation gelöscht werden damit die Domain bestehen bleibt.
        $params = WhmcsBridge::getRegistarConfig();
        $domainrobot = self::getApi($params);
        $dc = new DomainCancelation();
        $dc->setDomain($domain);
        $dc->setType(CancelationTypeConstants::DELETE);
        $dc->setExecution(ExecutionTypeConstants::EXPIRE);
        $domainCancelation = $domainrobot->domainCancelation->create($dc);
    }

    public static function domainCancelationDelete($domain){
        $params = WhmcsBridge::getRegistarConfig();
        $domainrobot = self::getApi($params);
        $domainrobot->domainCancelation->delete($domain);
    }

}
