<?php

namespace Commerce\Payments;

class ExpresspayCardPayment extends Payment implements \Commerce\Interfaces\Payment
{
    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->lang = $modx->commerce->getUserLanguage('expresspay_card');
    }

    public function getMarkup()
    {
        $out = [];

        if ($this->getSetting('isTest')) {
            $out[] = $this->lang['expresspay_card.test_mode'];
        }

        if (empty($this->getSetting('serviceId'))) {
            $out[] = $this->lang['expresspay_card.error_empty_serviceId'];
        }

        if (empty($this->getSetting('token'))) {
            $out[] = $this->lang['expresspay_card.error_empty_token'];
        }

        $out = implode('<br>', $out);

        if (!empty($out)) {
            $out = '<span class="error" style="color: red;">' . $out . '</span>';
        }

        return $out;
    }

    public function getPaymentLink()
    {
        $debug = !empty($this->getSetting('debug'));

        $processor = $this->modx->commerce->loadProcessor();
        $order     = $processor->getOrder();
        $fields    = $order['fields'];
        $currency  = ci()->currency->getCurrency($order['currency']);
        $payment   = $this->createPayment($order['id'], ci()->currency->convertToDefault($order['amount'], $currency['code']));

        if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
            $receipt['email'] = $order['email'];
        }
        if (!empty($order['phone'])) {
            $receipt['phone'] = substr(preg_replace('/[^\d]+/', '', $order['phone']), 0, 15);
        }
        $receipt['tax_system_code'] = $this->getSetting('tax_system_code');

        $fio = explode(" ", $order['name']);

        $request = array(
            "ServiceId"          => $this->getSetting('serviceId'),
            "AccountNo"          => $order['id'],
            "Expiration"         => '',
            "Amount"             => "11",
            "Currency"           => 933,
            "Info"               => 'Оплата заказа на сайте ' . $this->modx->getConfig('site_name'),
            "ReturnUrl"          => '',
            "FailUrl"            => '',
            "Language"           => 'ru',
            "SessionTimeoutSecs" => 1200,
            "ReturnType"         => 'json'
        );

        $secretWord = $this->getSetting('useSignature') ? $this->getSetting('secretWord') : '';

        $request['Signature'] = $this->compute_signature($request, $secretWord);

        $response = $this->sendRequestPOST($request);

        $response = json_decode($response, true);

        if (isset($response['Errors'])) {
            $this->log_info('Response', print_r($response, 1));
            $output_error =
                '<br />
            <h3>Ваш номер заказа: ##ORDER_ID##</h3>
            <p>При выполнении запроса произошла непредвиденная ошибка. Пожалуйста, повторите запрос позже или обратитесь в службу технической поддержки магазина</p>
            <input type="button" value="Продолжить" onClick=\'location.href="##HOME_URL##"\'>';

            $output_error = str_replace('##ORDER_ID##', $order['id'],  $output_error);

            $output_error = str_replace('##HOME_URL##', $this->modx->getConfig('site_url'),  $output_error);

            echo $output_error;
        } else {
            return  $response['FormUrl'];
        }
    }

    public function handleCallback()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            print "OK!";
        }

        $processor = $this->modx->commerce->loadProcessor();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            // Получение данных
            $json = $_POST['Data'];
            $signature = $_POST['Signature'];

            // Преобразуем из JSON в Array
            $data = json_decode($json, true);

            $id = $data['AccountNo'];

            if ($this->getSetting('useSignatureForNotif')) {

                $secretWord = $this->getSetting('secretWordForNotif');

                if ($signature == $this->computeSignature($json, $secretWord)) {
                    if ($data['CmdType'] == '3' && $data['Status'] == '3' || $data['Status'] == '6') {
                        $processor->changeStatus($id, 3); // Изменение статуса заказа на оплачен
                        header("HTTP/1.0 200 OK");
                        print $status = 'OK | payment received'; //Все успешно
                    } elseif ($data['CmdType'] == '3' && $data['Status'] == '5') {
                        $processor->changeStatus($id, 5); // Изменение статуса заказа на отменён
                        header("HTTP/1.0 200 OK");
                        print $status = 'OK | payment received'; //Все успешно
                    }
                } else {
                    header("HTTP/1.0 400 Bad Request");
                    print $status = 'FAILED | wrong notify signature  '; //Ошибка в параметрах
                }
            }
            if ($data['CmdType'] == '3' && $data['Status'] == '3' || $data['Status'] == '6') {
                $processor->changeStatus($id, 3); // Изменение статуса заказа на оплачен
                header("HTTP/1.0 200 OK");
                print $status = 'OK | payment received'; //Все успешно
            } elseif ($data['CmdType'] == '3' && $data['Status'] == '5') {
                $processor->changeStatus($id, 5); // Изменение статуса заказа на отменён
                header("HTTP/1.0 200 OK");
                print $status = 'OK | payment cancel'; //Все успешно
            } else {
                header("HTTP/1.0 200 Bad Request");
                print $status = 'FAILED | ID заказа неизвестен';
            }
        }

        return true;
    }

    // Проверка электронной подписи
    protected function computeSignature($json, $secretWord)
    {
        $hash = NULL;

        $secretWord = trim($secretWord);

        if (empty($secretWord))
            $hash = strtoupper(hash_hmac('sha1', $json, ""));
        else
            $hash = strtoupper(hash_hmac('sha1', $json, $secretWord));
        return $hash;
    }

    // Отправка POST запроса
    protected function sendRequestPOST($params)
    {
        $url = $this->getSetting('isTest') ? "https://sandbox-api.express-pay.by/v1/web_cardinvoices" : "https://api.express-pay.by/v1/web_cardinvoices";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

 //Вычисление цифровой подписи
 public function compute_signature($request_params, $secret_word)
 {
     $secret_word = trim($secret_word);
     $normalized_params = array_change_key_case($request_params, CASE_LOWER);
     $api_method = array(
         "serviceid",
         "accountno",
         "expiration",
         "amount",
         "currency",
         "info",
         "returnurl",
         "failurl",
         "language",
         "sessiontimeoutsecs",
         "expirationdate",
         "returntype"
     );

     $result = $this->getSetting('token');

     foreach ($api_method as $item)
         $result .= (isset($normalized_params[$item])) ? $normalized_params[$item] : '';

     $hash = strtoupper(hash_hmac('sha1', $result, $secret_word));

     return $hash;
 }

    private function log_info($name, $message)
    {
        $this->log($name, "INFO", $message);
    }

    private function log($name, $type, $message)
    {
        $log_url = dirname(__FILE__) . '/log';

        if (!file_exists($log_url)) {
            $is_created = mkdir($log_url, 0777);

            if (!$is_created)
                return;
        }

        $log_url .= '/express-pay-card' . date('Y.m.d') . '.log';

        file_put_contents($log_url, $type . " - IP - " . $_SERVER['REMOTE_ADDR'] . "; DATETIME - " . date("Y-m-d H:i:s") . "; USER AGENT - " . $_SERVER['HTTP_USER_AGENT'] . "; FUNCTION - " . $name . "; MESSAGE - " . $message . ';' . PHP_EOL, FILE_APPEND);
    }
}
