<?php

namespace Unetway\Robokassa;

use Exception;
use GuzzleHttp\Client;

class Robokassa
{

    /**
     * @var string
     */
    private string $payment_url = 'https://auth.robokassa.ru/Merchant/Index.aspx';

    /**
     * @var string
     */
    private string $recurrent_url = 'https://auth.robokassa.ru/Merchant/Recurring';

    /**
     * @var string
     */
    private string $sms_url = 'https://services.robokassa.ru/SMS/';

    /**
     * @var string
     */
    private string $web_service_url = 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx';

    /**
     * @var bool
     */
    private $is_test = false;

    /**
     * @var string
     */
    protected $password1;

    /**
     * @var string
     */
    protected $password2;

    /**
     * @var string
     */
    private $hashType = 'sha256';

    /**
     * @var mixed
     */
    private $login;

    /**
     * @var int
     */
    private int $strLenSms = 128;

    /**
     * @var array|string[]
     */
    private array $hashAlgoList = [
        'md5',
        'ripemd160',
        'sha1',
        'sha256',
        'sha384',
        'sha512'
    ];

    /**
     * Robokassa constructor.
     * @param $params
     * @throws Exception
     */
    public function __construct($params)
    {
        if (empty($params)) {
            throw new Exception('Params is not defined');
        }

        if (empty($params['login'])) {
            throw new Exception('Param login is not defined');
        }

        if (empty($params['password1'])) {
            throw new Exception('Param password1 is not defined');
        }

        if (empty($params['password2'])) {
            throw new Exception('Param password2 is not defined');
        }

        if (!empty($params['is_test'])) {
            if (empty($params['test_password1'])) {
                throw new Exception('Param test_password1 is not defined');
            }

            if (empty($params['test_password2'])) {
                throw new Exception('Param test_password2 is not defined');
            }

            $this->is_test = $params['is_test'];
        }

        if (!empty($params['hashType'])) {
            if (!in_array($params['hashType'], $this->hashAlgoList)) {
                $except = implode(', ', $this->hashAlgoList);
                throw new Exception("The hashType parameter can only the values: $except");
            }

            $this->hashType = $params['hashType'];
        }

        $this->login = $params['login'];
        $this->password1 = $this->is_test ? $params['test_password1'] : $params['password1'];
        $this->password2 = $this->is_test ? $params['test_password2'] : $params['password2'];
    }

    /**
     * Ссылка для оплаты
     *
     * @param $params
     * @return string
     * @throws Exception
     */
    public function generateLink($params): string
    {
        if (empty($params['InvoiceId'])) {
            throw new Exception('Param InvoiceId is not defined');
        }

        if (empty($params['OutSum'])) {
            throw new Exception('Param outSum is not defined');
        }

        if (empty($params['Description'])) {
            throw new Exception('Param Description is not defined');
        }

        $params ['MerchantLogin'] = $this->getLogin();

        $signatureParams = [
            'OutSum' => $params['OutSum'],
            'InvoiceId' => $params['InvoiceId'],
        ];

        if (!empty($params['OutSumCurrency'])) {
            $signatureParams['OutSumCurrency'] = $params['OutSumCurrency'];
        }

        if (!empty($params['UserIp'])) {
            $signatureParams['UserIp'] = $params['UserIp'];
        }

        if (!empty($params['Receipt'])) {
            $params['Receipt'] = json_encode($params['Receipt']);
            $signatureParams['Receipt'] = json_encode($params['Receipt']);
        }

        if (!empty($params['IsTest']) && $params['IsTest'] === true) {
            $params['IsTest'] = '1';
        }

        if (!empty($params['Recurring']) && $params['Recurring'] === true) {
            $params['Recurring'] = 'true';
        }

        $fields = $this->getFields($params);

        if (!empty($fields)) {
            $signatureParams = array_merge($signatureParams, $fields);
        }

        $params['SignatureValue'] = $this->generateSignature($signatureParams);

        return $this->payment_url . '?' . http_build_query($params);
    }

    /**
     * Отправка SMS
     *
     * @param $phone
     * @param $message
     * @return array|mixed
     * @throws Exception
     */
    public function sendSms($phone, $message): array
    {
        if (empty($phone)) {
            throw new Exception('Param phone is not defined');
        }

        if (empty($message)) {
            throw new Exception('Param message is not defined');
        }

        if (strlen($message) > $this->strLenSms) {
            throw new Exception("Maximum number of characters in a message: {$this->strLenSms}");
        }

        $query = http_build_query([
            'login' => $this->getLogin(),
            'phone' => $phone,
            'message' => $message,
            'signature' => $this->signatureSms($phone, $message),
        ]);

        $url = $this->sms_url . '?' . $query;

        $client = new Client();
        $response = $client->get($url)->getBody();

        if ($response->getStatusCode() === 200) {
            $json = $response->getBody()->getContents();
            return json_decode($json, true);
        }

        return [];
    }

    /**
     * Подпись для запроса отправки SMS
     *
     * @param $phone
     * @param $message
     * @return string
     */
    private function signatureSms($phone, $message): string
    {
        return hash($this->getHashType(), "{$this->getLogin()}:$phone:$message:{$this->getPassword1()}");
    }

    /**
     * Получение списка валют
     *
     * Используется для указания значений параметра IncCurrLabel
     * также используется для отображения доступных вариантов оплаты непосредственно на Вашем сайте
     * если Вы желаете дать больше информации своим клиентам.
     * @param $lang
     * @return array|mixed
     * @throws Exception
     */
    public function getCurrencies($lang): array
    {
        if (empty($lane)) {
            throw new Exception('Param lang is not defined');
        }

        $query = http_build_query([
            'MerchantLogin' => $this->getLogin(),
            'Language' => $lang,
        ]);

        $url = $this->getWebServiceUrl('GetCurrencies', $query);

        return $this->getRequest($url);
    }

    /**
     * Получение списка доступных способов оплаты
     *
     * Возвращает список способов оплаты, доступных для оплаты заказов указанного магазина/сайта.
     * @param $lang
     * @return array|mixed
     * @throws Exception
     */
    public function getPaymentMethods($lang): array
    {
        if (empty($lane)) {
            throw new Exception('Param lang is not defined');
        }

        $query = http_build_query([
            'MerchantLogin' => $this->getLogin(),
            'Language' => $lang,
        ]);

        $url = $this->getWebServiceUrl('GetPaymentMethods', $query);

        return $this->getRequest($url);
    }

    /**
     * Интерфейс расчёта суммы к оплате с учётом комиссии сервиса
     *
     * Позволяет рассчитать сумму, которую должен будет заплатить покупатель,
     * с учётом комиссий ROBOKASSA (согласно тарифам) и тех систем,
     * через которые покупатель решил совершать оплату заказа.
     * @param $outSum
     * @param $language
     * @param null $incCurrLabel
     * @return array|mixed
     * @throws Exception
     */
    public function getRates($outSum, $language, $incCurrLabel = null): array
    {
        if (empty($outSum)) {
            throw new Exception('Param outSum is not defined');
        }

        if (empty($language)) {
            throw new Exception('Param language is not defined');
        }

        if (!in_array($language, ['ru', 'en'])) {
            throw new Exception('The language parameter must be ru or en');
        }

        $params = [
            'MerchantLogin' => $this->getLogin(),
            'Language' => $language,
            'OutSum' => $outSum
        ];

        if (!empty($incCurrLabel)) {
            $params['IncCurrLabel'] = $incCurrLabel,
        }

        $query = http_build_query($params);

        $url = $this->getWebServiceUrl('GetRates', $query);

        return $this->getRequest($url);
    }

    /**
     * Интерфейс расчёта суммы к получению магазином
     *
     * Позволяет рассчитать сумму к получению, исходя из текущих курсов ROBOKASSA,
     * по сумме, которую заплатит пользователь.
     * Только для физических лиц.
     * @param $incSum
     * @param $incCurrLabel
     * @return array|mixed
     * @throws Exception
     */
    public function calcOutSumm($incSum, $incCurrLabel): array
    {
        if (empty($incSum)) {
            throw new Exception('Param incSum is not defined');
        }

        if (empty($incCurrLabel)) {
            throw new Exception('Param incCurrLabel is not defined');
        }

        $query = http_build_query([
            'MerchantLogin' => $this->getLogin(),
            'IncCurrLabel' => $incCurrLabel,
            'IncSum' => $incSum,
        ]);

        $url = $this->getWebServiceUrl('CalcOutSumm', $query);

        return $this->getRequest($url);
    }

    /**
     * Получение состояния оплаты счета
     *
     * Возвращает детальную информацию о текущем состоянии и реквизитах оплаты.
     * Необходимо помнить, что операция инициируется не в момент ухода пользователя на оплату,
     * а позже – после подтверждения его платежных реквизитов,
     * т.е. Вы вполне можете не находить операцию, которая по Вашему мнению уже должна начаться.
     * @param $invoiceID
     * @return array|mixed
     * @throws Exception
     */
    public function opState($invoiceID): array
    {
        if (empty($invoiceID)) {
            throw new Exception('Param invoiceID is not defined');
        }

        $query = http_build_query([
            'MerchantLogin' => $this->getLogin(),
            'InvoiceID' => $invoiceID,
            'Signature' => $this->signatureState($invoiceID)
        ]);

        $url = $this->getWebServiceUrl('OpState', $query);

        return $this->getRequest($url);
    }

    /**
     * Подпись для запроса проверки статуса счета
     *
     * @param $invoiceID
     * @return string
     */
    private function signatureState($invoiceID): string
    {
        return hash($this->getHashType(), "{$this->getLogin()}:$invoiceID:{$this->getPassword2()}");
    }

    /**
     * Периодические платежи (рекуррентные)
     *
     * @param $outSum
     * @param $invoiceID
     * @param $previousInvoiceID
     * @param $paramsOther
     * @return false|string
     * @throws Exception
     */
    public function recurrent($outSum, $invoiceID, $previousInvoiceID, $paramsOther)
    {
        if (empty($outSum)) {
            throw new Exception('Param outSum is not defined');
        }

        if (empty($invoiceID) || $invoiceID === 0) {
            throw new Exception('Param invoiceID is not defined');
        }

        if (empty($previousInvoiceID) || $previousInvoiceID === 0) {
            throw new Exception('Param previousInvoiceID is not defined');
        }

        if (!empty($paramsOther)) {
            if (!empty($paramsOther['IncCurrLabel'])) {
                throw new Exception('Param IncCurrLabel is not defined');
            }

            if (!empty($paramsOther['ExpirationDate'])) {
                throw new Exception('Param ExpirationDate is not defined');
            }

            if (!empty($paramsOther['Recurring'])) {
                throw new Exception('Param Recurring is not defined');
            }
        }

        $signatureValue = $this->generateSignature([
            'OutSum' => $outSum,
            'InvoiceID' => $invoiceID,
        ]);

        $paramsRequired = [
            'MerchantLogin' => $this->getLogin(),
            'InvoiceID' => $invoiceID,
            'PreviousInvoiceID' => $previousInvoiceID,
            'SignatureValue' => $signatureValue,
            'OutSum' => $outSum,
        ];

        $params = array_merge($paramsRequired, $paramsOther);

        $client = new Client();
        $response = $client->post($this->recurrent_url, [
            'form_params' => $params
        ]);

        if ($response->getStatusCode() === 200) {
            $res = $response->getBody()->getContents();

            if ($res === 'OK' . $invoiceID) {
                return $res;
            }
        }

        return false;
    }

    /**
     * @param $params
     * @return array
     */
    private function getFields($params): array
    {
        $fields = [];

        foreach ($params as $key => $value) {
            if (!preg_match('~^Shp_~iu', $key)) {
                continue;
            }

            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * @param $params
     * @param $required
     * @return string
     */
    private function getHashFields($params, $required): string
    {
        $fields = [];

        foreach ($params as $key => $value) {
            if (!preg_match('~^Shp_~iu', $key)) {
                continue;
            }

            $fields[] = $key . '=' . $value;
        }

        $hash = implode(':', $required);

        if (!empty($fields)) {
            $hash .= ':' . implode(':', $fields);
        }

        return $hash;
    }

    /**
     * @param $params
     * @param $password
     * @return bool
     */
    private function checkHash($params, $password): bool
    {
        $required = [
            $params['OutSum'],
            $params['InvId'],
            $password
        ];

        $hash = $this->getHashFields($params, $required);

        $crc = strtoupper($params['SignatureValue']);
        $my_crc = strtoupper(hash($this->getHashType(), $hash));

        return $my_crc === $crc;
    }

    /**
     * Проверка платежа на ResultURL
     *
     * @param $params
     * @return bool
     */
    public function checkResult($params): bool
    {
        return $this->checkHash($params, $this->getPassword2());
    }

    /**
     * Проверка платежа на SuccessURL
     *
     * @param $params
     * @return bool
     */
    public function checkSuccess($params): bool
    {
        return $this->checkHash($params, $this->getPassword1());
    }

    /**
     * Подпись для запроса оплаты
     *
     * @param $params
     * @return string
     */
    private function generateSignature($params): string
    {
        $required = [
            $this->getLogin(),
            $params['OutSum'],
            $params['InvoiceID'],
            $this->getPassword1(),
        ];

        $hash = $this->getHashFields($params, $required);

        return hash($this->getHashType(), $hash);
    }

    /**
     * @param $url
     * @return array|mixed
     */
    private function getRequest($url): array
    {
        $client = new Client();
        $response = $client->get($url)->getBody();

        if ($response->getStatusCode() === 200) {
            $xml = $response->getBody()->getContents();
            return $this->getXmlInArray($xml);
        }

        return [];
    }

    /**
     * @param $response
     * @return mixed
     */
    private function getXmlInArray($response)
    {
        $res = simplexml_load_string($response);
        $res = json_decode(json_encode((array)$res, JSON_NUMERIC_CHECK), TRUE);

        return $res;
    }

    /**
     * @param $segment
     * @param $query
     * @return string
     */
    private function getWebServiceUrl($segment, $query): string
    {
        return $this->web_service_url . '/' . $segment . '?' . $query;
    }

    /**
     * @return mixed
     */
    private function getLogin()
    {
        return $this->login;
    }

    /**
     * @return string
     */
    private function getPassword1(): string
    {
        return $this->password1;
    }

    /**
     * @return string
     */
    private function getPassword2(): string
    {
        return $this->password2;
    }

    /**
     * @return string
     */
    private function getHashType(): string
    {
        return $this->hashType;
    }

}