//<?php
/**
 * Payment ExpressPay_Card
 *
 * Express-Pay: Card payments processing
 *
 * @category    plugin
 * @version     0.0.1
 * @author      OOO "TriIncom"
 * @internal    @events OnRegisterPayments,OnBeforeOrderSending,OnManagerBeforeOrderRender
 * @internal    @properties &title=Название;text;Экспресс Платежи: Интернет-эквайринг &isTest=Использовать тестовый режим;list;Нет==0||Да==1; &serviceId=Номер услуги;text; &token=Токен;text; &useSignature=Использовать секретное слово для подписи счетов;list;Нет==0||Да==1; &secretWord=Секретное слово;text; &notifUrl=Адрес для получения уведомлений;text;https://домен вашего сайта/commerce/expresspay_card/payment-process &useSignatureForNotif=Использовать цифровую подпись для уведомлений;list;Нет==0||Да==1; &secretWordForNotif=Секретное слово для уведомлений;text;
 * @internal    @modx_category Commerce
 * @internal    @disabled 0
 * @internal    @installset base
*/

if (empty($modx->commerce) && !defined('COMMERCE_INITIALIZED')) {
    return;
}

$isSelectedPayment = !empty($order['fields']['payment_method']) && $order['fields']['payment_method'] == 'expresspay_card';
$commerce = ci()->commerce;
$lang = $commerce->getUserLanguage('expresspay_card');

switch ($modx->event->name) {
    case 'OnRegisterPayments': {
        $class = new \Commerce\Payments\ExpresspayCardPayment($modx, $params);

        if (empty($params['title'])) {
            $params['title'] = $lang['expresspay_card.caption'];
        }

        $commerce->registerPayment('expresspay_card', $params['title'], $class);
        break;
    }

    case 'OnBeforeOrderSending': {
        if ($isSelectedPayment) {
            $FL->setPlaceholder('extra', $FL->getPlaceholder('extra', '') . $commerce->loadProcessor()->populateOrderPaymentLink());
        }

        break;
    }
}
