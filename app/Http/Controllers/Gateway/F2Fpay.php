<?php

namespace App\Http\Controllers\Gateway;

use App\Models\Payment;
use Auth;
use Exception;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Log;
use Payment\Client;
use Payment\Exceptions\ClassNotFoundException;
use Response;

class F2Fpay extends AbstractPayment
{
    private static $aliConfig;

    public function __construct()
    {
        self::$aliConfig = [
            'use_sandbox'     => false,
            'app_id'          => sysConfig('f2fpay_app_id'),
            'sign_type'       => 'RSA2',
            'ali_public_key'  => sysConfig('f2fpay_public_key'),
            'rsa_private_key' => sysConfig('f2fpay_private_key'),
            'limit_pay'       => [],
            'notify_url'      => (sysConfig('website_callback_url') ?: sysConfig('website_url')).'/callback/notify?method=f2fpay',
            'return_url'      => sysConfig('website_url').'/invoices',
            'fee_type'        => 'CNY',
        ];
    }

    public function purchase($request): JsonResponse
    {
        $payment = $this->creatNewPayment(Auth::id(), $request->input('id'), $request->input('amount'));

        $data = [
            'body'        => '',
            'subject'     => sysConfig('subject_name') ?: sysConfig('website_name'),
            'trade_no'    => $payment->trade_no,
            'time_expire' => time() + 900, // 必须 15分钟 内付款
            'amount'      => $payment->amount,
        ];

        try {
            $result = (new Client(Client::ALIPAY, self::$aliConfig))->pay(Client::ALI_CHANNEL_QR, $data);
        } catch (InvalidArgumentException $e) {
            Log::error("【支付宝当面付】输入信息错误: ".$e->getMessage());
            exit;
        } catch (ClassNotFoundException $e) {
            Log::error("【支付宝当面付】未知类型: ".$e->getMessage());
            exit;
        } catch (Exception $e) {
            Log::error("【支付宝当面付】错误: ".$e->getMessage());
            exit;
        }

        $payment->update(['qr_code' => 1, 'url' => $result['qr_code']]);

        return Response::json(['status' => 'success', 'data' => $payment->trade_no, 'message' => '创建订单成功!']);
    }

    public function notify($request): void
    {
        $data = [
            'trade_no'       => $request->input('out_trade_no'),
            'transaction_id' => $request->input('trade_no'),
        ];

        try {
            $result = (new Client(Client::ALIPAY, self::$aliConfig))->tradeQuery($data);
            Log::info("【支付宝当面付】回调验证查询：".var_export($result, true));
        } catch (InvalidArgumentException $e) {
            Log::error("【支付宝当面付】回调信息错误: ".$e->getMessage());
            exit;
        } catch (ClassNotFoundException $e) {
            Log::error("【支付宝当面付】未知类型: ".$e->getMessage());
            exit;
        } catch (Exception $e) {
            Log::error("【支付宝当面付】错误: ".$e->getMessage());
            exit;
        }

        if ($result['code'] == 10000 && $result['msg'] === "Success") {
            if ($_POST['trade_status'] === 'TRADE_FINISHED' || $_POST['trade_status'] === 'TRADE_SUCCESS') {
                $payment = Payment::whereTradeNo($request->input('out_trade_no'))->first();
                if ($payment) {
                    $ret = $payment->order->update(['status' => 2]);
                    if ($ret) {
                        exit('success');
                    }
                }
            } else {
                Log::info('支付宝当面付-POST:交易失败');
            }
        } else {
            Log::info('支付宝当面付-POST:验证失败');
        }

        // 返回验证结果
        exit('fail');
    }
}
