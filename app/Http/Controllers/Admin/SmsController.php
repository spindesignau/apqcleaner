<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SmsMessage;
use App\Models\SmsTemplate;
use App\Services\Audit;
use App\Services\Settings;
use App\Services\Sms\MobileMessageGateway;
use App\Services\Sms\SmsDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SmsController extends Controller
{
    public function index(Request $request, Settings $settings): View
    {
        $status = $request->query('status');
        $messages = SmsMessage::query()->with(['client', 'user', 'appointment'])
            ->when($status === 'inbound', fn ($q) => $q->where('direction', 'inbound'))
            ->when($status && $status !== 'inbound', fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(50)->withQueryString();

        return view('admin.sms.index', [
            'messages' => $messages,
            'status' => $status,
            'enabled' => (bool) config('mobilemessage.enabled'),
            'templates' => SmsTemplate::orderBy('id')->get(),
            'settings' => collect(['reminders_enabled', 'reminder_time', 'quiet_end', 'quiet_start', 'notify_client_on_cancel'])
                ->mapWithKeys(fn ($k) => [$k => $settings->get('sms.'.$k)])->all() + [
                    'business_phone' => $settings->get('business.phone'),
                    'notify_staff_on_cancel' => $settings->get('notify_staff_on_cancel'),
                ],
            'monthCount' => SmsMessage::where('direction', 'outbound')->whereIn('status', ['sent', 'delivered'])->where('created_at', '>=', now()->startOfMonth())->count(),
            'failedCount' => SmsMessage::where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
            'optedOut' => Client::where('sms_opt_in', false)->count(),
            'webhookBase' => config('mobilemessage.webhook_token') ? url('/webhooks/mobile-message/'.config('mobilemessage.webhook_token')) : null,
        ]);
    }

    public function updateSettings(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'reminder_time' => ['required', 'date_format:H:i'],
            'quiet_end' => ['required', 'date_format:H:i'],
            'quiet_start' => ['required', 'date_format:H:i', 'after:quiet_end'],
            'business_phone' => ['nullable', 'string', 'max:30'],
        ], attributes: collect(['reminder_time', 'quiet_end', 'quiet_start', 'business_phone'])->mapWithKeys(fn ($k) => [$k => __('sms.fields.'.$k)])->all());

        $settings->set('sms.reminders_enabled', $request->boolean('reminders_enabled'));
        $settings->set('sms.notify_client_on_cancel', $request->boolean('notify_client_on_cancel'));
        $settings->set('notify_staff_on_cancel', $request->boolean('notify_staff_on_cancel'));
        foreach (['reminder_time', 'quiet_end', 'quiet_start'] as $k) {
            $settings->set('sms.'.$k, $data[$k]);
        }
        $settings->set('business.phone', $data['business_phone'] ?? null);
        Audit::log('sms.settings_updated', null, after: $data + ['reminders_enabled' => $request->boolean('reminders_enabled')]);

        return back()->with('status', __('sms.settings_saved'));
    }

    public function updateTemplate(Request $request, SmsTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'body_en' => ['required', 'string', 'max:612'],
            'body_es' => ['required', 'string', 'max:612'],
        ]);
        foreach (['body_en', 'body_es'] as $field) {
            preg_match_all('/\{[a-z_]+\}/', $data[$field], $m);
            $unknown = array_diff($m[0], SmsTemplate::PLACEHOLDERS);
            if ($unknown) {
                return back()->withErrors([$field.'_'.$template->id => __('sms.unknown_placeholder', ['list' => implode(', ', $unknown)])])->withInput();
            }
        }
        $template->update($data);
        Audit::log('sms.template_updated', $template);

        return back()->with('status', __('sms.template_saved'));
    }

    /** Shows the credit balance and whether the configured sender ID is approved on the account. */
    public function checkAccount(MobileMessageGateway $gateway): RedirectResponse
    {
        if (! filled(config('mobilemessage.username')) || ! filled(config('mobilemessage.password'))) {
            return back()->with('warning', __('sms.not_configured'));
        }
        $result = $gateway->account();
        if (! $result['ok']) {
            return back()->with('warning', __('sms.check_failed', ['error' => $result['error']]));
        }
        $sender = (string) config('mobilemessage.sender');
        $message = __('sms.check_ok', ['balance' => rtrim(rtrim(number_format((float) $result['balance'], 2), '0'), '.'), 'senders' => implode(', ', $result['senders']) ?: '—']);

        return in_array($sender, $result['senders'], true) || $result['senders'] === []
            ? back()->with('status', $message)
            : back()->with('status', $message)->with('warning', __('sms.sender_not_approved', ['sender' => $sender]));
    }

    public function sendToClient(Request $request, Client $client, SmsDispatcher $sms): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:612']], attributes: ['body' => __('sms.message')]);
        $message = $sms->freeText($client, $data['body'], $request->user());

        return back()->with($message->status === 'sent' ? 'status' : 'warning', $message->status === 'sent'
            ? __('sms.sent_to', ['name' => $client->display_name])
            : __('sms.reminder_failed', ['reason' => $message->status_detail]));
    }
}
