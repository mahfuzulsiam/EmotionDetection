<?php
/**
 * MAS Global NOWPayments Gateway
 * Version: 1.0.0
 *
 * Upload this file to Laravel public/ and open it once in a browser.
 * The installer creates automatic backups and supports repair/rollback.
 * No API key or IPN secret is embedded in this file.
 */
declare(strict_types=1);

const MAS_NP_INSTALLER_VERSION = '1.0.0';
const MAS_NP_INSTALLER_NAME = 'Global NOWPayments Gateway';

@set_time_limit(300);
@ini_set('display_errors', '1');
error_reporting(E_ALL);

function masnp_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function masnp_root(): string
{
    foreach ([__DIR__, dirname(__DIR__), dirname(__DIR__, 2)] as $candidate) {
        if (is_file($candidate . '/artisan') && is_file($candidate . '/bootstrap/app.php')) {
            return realpath($candidate) ?: $candidate;
        }
    }

    throw new RuntimeException('Laravel root was not detected. Upload this installer inside the public directory.');
}

function masnp_state_path(string $root): string
{
    return $root . '/storage/app/mas-nowpayments-installer-state.json';
}

function masnp_read_state(string $root): array
{
    $path = masnp_state_path($root);
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function masnp_write_file(string $path, string $contents): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create directory: ' . $directory);
    }

    $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Could not write temporary file: ' . $path);
    }

    @chmod($temporary, 0644);

    if (is_file($path) && !@unlink($path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not replace file: ' . $path);
    }

    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not activate file: ' . $path);
    }
}

function masnp_write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode installer state.');
    }
    masnp_write_file($path, $json);
}

function masnp_boot(string $root): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    if (!is_file($root . '/vendor/autoload.php')) {
        throw new RuntimeException('vendor/autoload.php is missing. Run Composer install before using this installer.');
    }

    require_once $root . '/vendor/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    $booted = true;
}

function masnp_clear_cache(string $root): int
{
    $removed = 0;

    foreach (glob($root . '/storage/framework/views/*.php') ?: [] as $file) {
        if (@unlink($file)) {
            $removed++;
        }
    }

    foreach (glob($root . '/bootstrap/cache/*.php') ?: [] as $file) {
        if (in_array(basename($file), ['packages.php', 'services.php'], true)) {
            continue;
        }
        if (@unlink($file)) {
            $removed++;
        }
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    return $removed;
}

function masnp_replace_marker(string $contents, string $start, string $end, string $block): string
{
    $pattern = '#' . preg_quote($start, '#') . '.*?' . preg_quote($end, '#') . '#s';
    if (preg_match($pattern, $contents)) {
        $updated = preg_replace($pattern, $block, $contents, 1);
        if (!is_string($updated)) {
            throw new RuntimeException('Could not replace marker block: ' . $start);
        }
        return $updated;
    }

    return $contents;
}

function masnp_append_marker(string $contents, string $start, string $end, string $block): string
{
    if (strpos($contents, $start) !== false) {
        return masnp_replace_marker($contents, $start, $end, $block);
    }

    return rtrim($contents) . "\n\n" . $block . "\n";
}

function masnp_patch_admin_menu(string $contents): string
{
    $start = '{{-- MAS_GLOBAL_PAYMENTS_MENU_START --}}';
    $end = '{{-- MAS_GLOBAL_PAYMENTS_MENU_END --}}';
    $block = <<<'BLADE'
{{-- MAS_GLOBAL_PAYMENTS_MENU_START --}}
@if(Auth::check() && optional(Auth::user()->role)->name == 'administrator')
<li class="nav-item {{ request()->is('admin/payments*') ? 'active' : '' }}">
    <a class="nav-link" href="{{ url('/admin/payments/gateways') }}">
        <i class="fas fa-fw fa-wallet"></i>
        <span>Payments</span>
    </a>
</li>
@endif
{{-- MAS_GLOBAL_PAYMENTS_MENU_END --}}
BLADE;

    if (strpos($contents, $start) !== false) {
        return masnp_replace_marker($contents, $start, $end, $block);
    }

    foreach (['{{-- MAS_SEPARATE_EXTENSIONS_MENU_START --}}', '{{-- MAS_SEPARATE_PLUGINS_MENU_START --}}', '{{-- MAS_AI_CONTENT_ENGINE_MENU_START --}}'] as $needle) {
        $position = strpos($contents, $needle);
        if ($position !== false) {
            return substr($contents, 0, $position) . $block . "\n" . substr($contents, $position);
        }
    }

    $position = strripos($contents, '</ul>');
    if ($position === false) {
        throw new RuntimeException('Could not locate the admin sidebar insertion point.');
    }

    return substr($contents, 0, $position) . $block . "\n" . substr($contents, $position);
}

function masnp_insert_content_block(string $contents, string $block): string
{
    $start = '{{-- MAS_NOWPAYMENTS_CHECKOUT_BUTTON_START --}}';
    $end = '{{-- MAS_NOWPAYMENTS_CHECKOUT_BUTTON_END --}}';

    if (strpos($contents, $start) !== false) {
        return masnp_replace_marker($contents, $start, $end, $block);
    }

    $sectionPositions = [];
    foreach (["@section('content')", '@section("content")'] as $sectionNeedle) {
        $p = strpos($contents, $sectionNeedle);
        if ($p !== false) {
            $sectionPositions[] = $p;
        }
    }

    if (!$sectionPositions) {
        return $contents;
    }

    $sectionStart = min($sectionPositions);
    $sectionEnd = strpos($contents, '@endsection', $sectionStart);
    if ($sectionEnd === false) {
        return $contents;
    }

    return substr($contents, 0, $sectionEnd) . "\n" . $block . "\n" . substr($contents, $sectionEnd);
}

function masnp_payload(): array
{
    $service = <<<'PHPFILE'
<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class MasNowPaymentsService
{
    public const PROVIDER = 'nowpayments';

    public function defaultConfig(): array
    {
        return [
            'api_key' => '',
            'ipn_secret' => '',
            'base_url' => 'https://api.nowpayments.io/v1',
            'price_currency' => 'usd',
            'pay_currency' => 'usdttrc20',
            'payout_currency' => 'usdttrc20',
            'fixed_rate' => true,
            'fee_paid_by_user' => false,
            'underpayment_tolerance' => 1.0,
            'payment_expiry_minutes' => 60,
        ];
    }

    public function gateway(): ?object
    {
        if (!Schema::hasTable('mas_payment_gateways')) {
            return null;
        }

        return DB::table('mas_payment_gateways')->where('provider', self::PROVIDER)->first();
    }

    public function config(): array
    {
        $defaults = $this->defaultConfig();
        $gateway = $this->gateway();

        if (!$gateway || empty($gateway->config_encrypted)) {
            return $defaults;
        }

        try {
            $decoded = json_decode(Crypt::decryptString((string) $gateway->config_encrypted), true);
            return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    public function enabled(): bool
    {
        $gateway = $this->gateway();
        $config = $this->config();

        return (bool) ($gateway->enabled ?? false)
            && trim((string) ($config['api_key'] ?? '')) !== ''
            && trim((string) ($config['ipn_secret'] ?? '')) !== '';
    }

    public function saveConfig(array $input): void
    {
        $current = $this->config();

        foreach (['api_key', 'ipn_secret'] as $secret) {
            if (!array_key_exists($secret, $input) || trim((string) $input[$secret]) === '') {
                $input[$secret] = $current[$secret] ?? '';
            }
        }

        $config = array_merge($this->defaultConfig(), $input);
        $config['base_url'] = rtrim((string) $config['base_url'], '/');
        $config['price_currency'] = strtolower(trim((string) $config['price_currency']));
        $config['pay_currency'] = strtolower(trim((string) $config['pay_currency']));
        $config['payout_currency'] = strtolower(trim((string) $config['payout_currency']));
        $config['fixed_rate'] = !empty($config['fixed_rate']);
        $config['fee_paid_by_user'] = !empty($config['fee_paid_by_user']);
        $config['underpayment_tolerance'] = max(0, min(20, (float) $config['underpayment_tolerance']));
        $config['payment_expiry_minutes'] = max(10, min(1440, (int) $config['payment_expiry_minutes']));

        DB::table('mas_payment_gateways')->updateOrInsert(
            ['provider' => self::PROVIDER],
            [
                'display_name' => 'NOWPayments',
                'enabled' => !empty($input['enabled']),
                'config_encrypted' => Crypt::encryptString(json_encode($config, JSON_UNESCAPED_SLASHES)),
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ]
        );
    }

    public function testConnection(): array
    {
        $config = $this->config();
        $response = Http::timeout(25)
            ->acceptJson()
            ->withHeaders(['x-api-key' => (string) $config['api_key']])
            ->get(rtrim((string) $config['base_url'], '/') . '/status');

        $ok = $response->successful();
        DB::table('mas_payment_gateways')->where('provider', self::PROVIDER)->update([
            'last_tested_at' => now(),
            'last_test_status' => $ok ? 'success' : 'failed',
            'updated_at' => now(),
        ]);

        if (!$ok) {
            throw new RuntimeException('NOWPayments connection failed: HTTP ' . $response->status() . ' ' . $response->body());
        }

        return ['ok' => true, 'response' => $response->json() ?: $response->body()];
    }

    public function createForSource(string $sourceType, int $sourceId, ?int $userId = null, array $context = []): object
    {
        if (!$this->enabled()) {
            throw new RuntimeException('NOWPayments is not enabled or credentials are incomplete.');
        }

        $source = $this->resolveSource($sourceType, $sourceId, $userId, $context);
        $config = $this->config();
        $priceCurrency = strtolower((string) $source['currency']);

        if ($priceCurrency !== strtolower((string) $config['price_currency'])) {
            throw new RuntimeException(
                'This payment is in ' . strtoupper($priceCurrency) . ', but NOWPayments is configured for ' .
                strtoupper((string) $config['price_currency']) . '. Update the gateway currency or the order currency.'
            );
        }

        $existing = DB::table('mas_payment_transactions')
            ->where('provider', self::PROVIDER)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNotIn('payment_status', ['finished', 'failed', 'refunded', 'expired'])
            ->where('created_at', '>=', now()->subDays(2))
            ->orderByDesc('id')
            ->first();

        if ($existing && !empty($existing->provider_payment_id)) {
            return $existing;
        }

        $uuid = (string) Str::uuid();
        $accessToken = Str::random(48);
        $providerOrderId = 'MAS-' . strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $sourceType), 0, 6)) . '-' . $sourceId . '-' . strtoupper(Str::random(6));
        $idempotencyKey = hash('sha256', self::PROVIDER . '|' . $sourceType . '|' . $sourceId . '|' . $providerOrderId);

        $payload = [
            'price_amount' => round((float) $source['amount'], 8),
            'price_currency' => $priceCurrency,
            'pay_currency' => (string) $config['pay_currency'],
            'payout_currency' => (string) $config['payout_currency'],
            'order_id' => $providerOrderId,
            'order_description' => (string) $source['description'],
            'ipn_callback_url' => url('/api/webhooks/nowpayments'),
            'is_fixed_rate' => (bool) $config['fixed_rate'],
            'is_fee_paid_by_user' => (bool) $config['fee_paid_by_user'],
        ];

        $response = $this->request('post', '/payment', $payload);
        $paymentId = (string) ($response['payment_id'] ?? '');

        if ($paymentId === '' || empty($response['pay_address']) || empty($response['pay_amount'])) {
            throw new RuntimeException('NOWPayments did not return a usable payment address.');
        }

        $now = now();
        $id = DB::table('mas_payment_transactions')->insertGetId([
            'uuid' => $uuid,
            'access_token' => hash('sha256', $accessToken),
            'user_id' => $userId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_reference' => (string) ($source['reference'] ?? ''),
            'provider' => self::PROVIDER,
            'provider_payment_id' => $paymentId,
            'provider_order_id' => $providerOrderId,
            'idempotency_key' => $idempotencyKey,
            'amount' => (float) $source['amount'],
            'price_currency' => $priceCurrency,
            'pay_amount' => (float) $response['pay_amount'],
            'pay_currency' => strtolower((string) ($response['pay_currency'] ?? $config['pay_currency'])),
            'actually_paid' => 0,
            'pay_address' => (string) $response['pay_address'],
            'payment_status' => (string) ($response['payment_status'] ?? 'waiting'),
            'order_description' => (string) $source['description'],
            'success_url' => (string) ($source['success_url'] ?? url('/')),
            'cancel_url' => (string) ($source['cancel_url'] ?? url('/')),
            'expires_at' => $now->copy()->addMinutes((int) $config['payment_expiry_minutes']),
            'raw_response' => json_encode($response, JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->event($id, 'created', (string) ($response['payment_status'] ?? 'waiting'), $response);
        $transaction = DB::table('mas_payment_transactions')->where('id', $id)->first();
        $transaction->plain_access_token = $accessToken;

        return $transaction;
    }

    public function reconcileByUuid(string $uuid): object
    {
        $transaction = DB::table('mas_payment_transactions')->where('uuid', $uuid)->first();
        if (!$transaction) {
            throw new RuntimeException('Payment transaction was not found.');
        }

        $providerData = $this->paymentStatus((string) $transaction->provider_payment_id);
        $this->applyProviderStatus((int) $transaction->id, $providerData, 'recheck');

        return DB::table('mas_payment_transactions')->where('id', $transaction->id)->first();
    }

    public function processWebhook(array $payload, string $signature): object
    {
        if (!$this->verifySignature($payload, $signature)) {
            throw new RuntimeException('Invalid NOWPayments IPN signature.');
        }

        $paymentId = (string) ($payload['payment_id'] ?? '');
        $orderId = (string) ($payload['order_id'] ?? '');

        $query = DB::table('mas_payment_transactions')->where('provider', self::PROVIDER);
        if ($paymentId !== '') {
            $query->where('provider_payment_id', $paymentId);
        } elseif ($orderId !== '') {
            $query->where('provider_order_id', $orderId);
        } else {
            throw new RuntimeException('Webhook does not contain a payment identifier.');
        }

        $transaction = $query->first();
        if (!$transaction) {
            throw new RuntimeException('Payment transaction does not exist locally.');
        }

        DB::table('mas_payment_transactions')->where('id', $transaction->id)->update([
            'raw_webhook' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        $providerData = $this->paymentStatus((string) $transaction->provider_payment_id);
        $this->applyProviderStatus((int) $transaction->id, $providerData, 'webhook');

        return DB::table('mas_payment_transactions')->where('id', $transaction->id)->first();
    }

    public function paymentStatus(string $paymentId): array
    {
        return $this->request('get', '/payment/' . rawurlencode($paymentId));
    }

    public function verifySignature(array $payload, string $signature): bool
    {
        $config = $this->config();
        $secret = trim((string) ($config['ipn_secret'] ?? ''));
        if ($secret === '' || trim($signature) === '') {
            return false;
        }

        $sorted = $this->sortRecursive($payload);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        $expected = hash_hmac('sha512', $json, $secret);
        return hash_equals(strtolower($expected), strtolower(trim($signature)));
    }

    private function applyProviderStatus(int $transactionId, array $providerData, string $eventType): void
    {
        $status = strtolower((string) ($providerData['payment_status'] ?? 'waiting'));
        $actuallyPaid = (float) ($providerData['actually_paid'] ?? $providerData['pay_amount'] ?? 0);

        DB::table('mas_payment_transactions')->where('id', $transactionId)->update([
            'payment_status' => $status,
            'actually_paid' => $actuallyPaid,
            'raw_response' => json_encode($providerData, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        $this->event($transactionId, $eventType, $status, $providerData);

        if ($status === 'finished') {
            $this->complete($transactionId, $providerData);
        }
    }

    public function complete(int $transactionId, array $providerData): void
    {
        $provisionOrderId = null;

        DB::transaction(function () use ($transactionId, $providerData, &$provisionOrderId) {
            $transaction = DB::table('mas_payment_transactions')->where('id', $transactionId)->lockForUpdate()->first();
            if (!$transaction || !empty($transaction->completed_at)) {
                return;
            }

            if (strtolower((string) ($providerData['payment_status'] ?? '')) !== 'finished') {
                throw new RuntimeException('Only a finished NOWPayments transaction can complete an order.');
            }

            if ((string) ($providerData['payment_id'] ?? '') !== (string) $transaction->provider_payment_id) {
                throw new RuntimeException('NOWPayments payment ID mismatch.');
            }

            if ((string) ($providerData['order_id'] ?? '') !== (string) $transaction->provider_order_id) {
                throw new RuntimeException('NOWPayments order ID mismatch.');
            }

            if (strtolower((string) ($providerData['pay_currency'] ?? '')) !== strtolower((string) $transaction->pay_currency)) {
                throw new RuntimeException('NOWPayments currency mismatch.');
            }

            $config = $this->config();
            $required = (float) $transaction->pay_amount;
            $actual = (float) ($providerData['actually_paid'] ?? 0);
            $minimum = $required * (1 - ((float) $config['underpayment_tolerance'] / 100));

            if ($actual + 0.00000001 < $minimum) {
                throw new RuntimeException('Received amount is below the allowed payment tolerance.');
            }

            DB::table('mas_payment_transactions')->where('id', $transactionId)->update([
                'payment_status' => 'finished',
                'actually_paid' => $actual,
                'completed_at' => now(),
                'raw_response' => json_encode($providerData, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

            if ($transaction->source_type === 'service_order_payment') {
                $payment = DB::table('service_order_payments')->where('id', $transaction->source_id)->first();
                if (!$payment) {
                    throw new RuntimeException('The linked service payment no longer exists.');
                }

                $this->updateAvailableColumns('service_order_payments', (int) $payment->id, [
                    'status' => 'verified',
                    'method' => self::PROVIDER,
                    'verified_at' => now(),
                    'paid_at' => now(),
                    'transaction_id' => (string) $transaction->provider_payment_id,
                    'raw_response' => json_encode($providerData, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);

                $order = DB::table('service_orders')->where('id', $payment->order_id)->first();
                if ($order) {
                    $updates = ['updated_at' => now()];
                    $stage = strtolower((string) ($payment->payment_stage ?? 'full'));
                    if ($stage === 'advance') {
                        $updates['paid_advance'] = (float) $payment->amount;
                        $updates['payment_status'] = 'advance_paid';
                        $updates['order_status'] = 'in_progress';
                    } else {
                        $updates['paid_remaining'] = (float) $payment->amount;
                        $updates['payment_status'] = 'fully_paid';
                    }
                    $this->updateAvailableColumns('service_orders', (int) $order->id, $updates);

                    if ((string) ($order->order_source ?? '') === 'plugin_purchase') {
                        $provisionOrderId = (int) $order->id;
                    }
                }
            } elseif ($transaction->source_type === 'extension_plan') {
                $plan = DB::table('mas_extension_plans')->where('id', $transaction->source_id)->first();
                if (!$plan || !$transaction->user_id) {
                    throw new RuntimeException('The linked extension plan is unavailable.');
                }

                $now = now();
                $cycle = strtolower((string) ($plan->billing_cycle ?? 'yearly'));
                $expires = null;
                if (in_array($cycle, ['monthly', 'month'], true)) {
                    $expires = $now->copy()->addMonth();
                } elseif (!in_array($cycle, ['lifetime', 'one_time', 'onetime'], true)) {
                    $expires = $now->copy()->addYear();
                }

                DB::table('mas_extension_subscriptions')->updateOrInsert(
                    ['user_id' => $transaction->user_id, 'extension_id' => $plan->extension_id],
                    [
                        'plan_id' => $plan->id,
                        'status' => 'active',
                        'starts_at' => $now,
                        'trial_ends_at' => null,
                        'expires_at' => $expires,
                        'source' => 'nowpayments',
                        'admin_note' => 'Activated from global NOWPayments transaction ' . $transaction->uuid,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        });

        if ($provisionOrderId && class_exists(\App\Services\MasPluginPurchaseService::class)) {
            try {
                app(\App\Services\MasPluginPurchaseService::class)->provisionByServiceOrder($provisionOrderId);
            } catch (\Throwable $e) {
                $this->event($transactionId, 'provision_error', 'finished', ['message' => $e->getMessage()]);
            }
        }

        try {
            event('mas.payment.completed', [DB::table('mas_payment_transactions')->where('id', $transactionId)->first()]);
        } catch (\Throwable $e) {
            // Optional application listeners must not break payment completion.
        }
    }

    private function resolveSource(string $sourceType, int $sourceId, ?int $userId, array $context): array
    {
        if ($sourceType === 'service_order_payment') {
            if (!Schema::hasTable('service_order_payments') || !Schema::hasTable('service_orders')) {
                throw new RuntimeException('Service Order payment tables are not installed.');
            }

            $payment = DB::table('service_order_payments')->where('id', $sourceId)->first();
            if (!$payment) {
                throw new RuntimeException('Payment request was not found.');
            }

            $order = DB::table('service_orders')->where('id', $payment->order_id)->first();
            if (!$order) {
                throw new RuntimeException('The linked order was not found.');
            }

            if (in_array(strtolower((string) ($payment->status ?? '')), ['verified', 'paid'], true)) {
                throw new RuntimeException('This payment request is already paid.');
            }

            $isPlugin = (string) ($order->order_source ?? '') === 'plugin_purchase';

            return [
                'amount' => (float) $payment->amount,
                'currency' => strtolower((string) ($payment->currency ?: ($order->currency ?? 'USD'))),
                'description' => trim((string) ($order->order_no ?? ('Order #' . $order->id)) . ' ' . (string) ($payment->payment_stage ?? 'payment')),
                'reference' => (string) ($order->order_no ?? $order->id),
                'success_url' => $isPlugin
                    ? url('/plugin-purchase/' . $order->id . '/payment')
                    : url('/client/payments'),
                'cancel_url' => $isPlugin
                    ? url('/plugin-purchase/' . $order->id . '/payment')
                    : url('/client/payments'),
            ];
        }

        if ($sourceType === 'extension_plan') {
            if (!$userId) {
                throw new RuntimeException('Please sign in before purchasing an extension plan.');
            }
            if (!Schema::hasTable('mas_extension_plans') || !Schema::hasTable('mas_extensions')) {
                throw new RuntimeException('Extensions system is not installed.');
            }

            $plan = DB::table('mas_extension_plans')->where('id', $sourceId)->where('status', 'active')->first();
            if (!$plan) {
                throw new RuntimeException('Extension plan was not found.');
            }
            $extension = DB::table('mas_extensions')->where('id', $plan->extension_id)->first();

            return [
                'amount' => (float) $plan->price,
                'currency' => strtolower((string) ($plan->currency ?: 'USD')),
                'description' => trim((string) ($extension->name ?? 'Extension') . ' - ' . (string) $plan->name),
                'reference' => 'extension-plan-' . $plan->id,
                'success_url' => url('/client/extensions'),
                'cancel_url' => url('/client/extensions'),
            ];
        }

        throw new RuntimeException('Unsupported payment source.');
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $config = $this->config();
        $url = rtrim((string) $config['base_url'], '/') . '/' . ltrim($path, '/');
        $client = Http::timeout(35)->acceptJson()->withHeaders([
            'x-api-key' => (string) $config['api_key'],
            'Content-Type' => 'application/json',
        ]);

        $response = strtolower($method) === 'get'
            ? $client->get($url, $payload)
            : $client->post($url, $payload);

        $json = $response->json();
        if (!$response->successful() || !is_array($json)) {
            $message = is_array($json)
                ? (string) ($json['message'] ?? $json['error'] ?? $response->body())
                : $response->body();
            throw new RuntimeException('NOWPayments API error (HTTP ' . $response->status() . '): ' . $message);
        }

        return $json;
    }

    private function sortRecursive(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        return $value;
    }

    private function updateAvailableColumns(string $table, int $id, array $values): void
    {
        $filtered = [];
        foreach ($values as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $filtered[$column] = $value;
            }
        }
        if ($filtered) {
            DB::table($table)->where('id', $id)->update($filtered);
        }
    }

    private function event(int $transactionId, string $eventType, string $status, array $payload): void
    {
        if (!Schema::hasTable('mas_payment_events')) {
            return;
        }

        DB::table('mas_payment_events')->insert([
            'transaction_id' => $transactionId,
            'event_type' => $eventType,
            'status' => $status,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }
}
PHPFILE;

    $publicController = <<<'PHPFILE'
<?php

namespace App\Http\Controllers;

use App\Services\MasNowPaymentsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MasNowPaymentsController extends Controller
{
    public function create(Request $request, MasNowPaymentsService $service)
    {
        $data = $request->validate([
            'source_type' => 'required|in:service_order_payment,extension_plan',
            'source_id' => 'required|integer|min:1',
            'payment_token' => 'nullable|string|max:191',
        ]);

        $this->authorizeSource($request, (string) $data['source_type'], (int) $data['source_id']);

        try {
            $transaction = $service->createForSource(
                (string) $data['source_type'],
                (int) $data['source_id'],
                Auth::id(),
                $data
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $query = [];
        if (!empty($transaction->plain_access_token)) {
            $query['access'] = $transaction->plain_access_token;
        }

        return redirect()->route('mas.nowpayments.show', array_merge(['uuid' => $transaction->uuid], $query));
    }

    public function show(Request $request, string $uuid)
    {
        $transaction = $this->transactionForRequest($request, $uuid);
        return view('mas_payments.pay', compact('transaction'));
    }

    public function status(Request $request, string $uuid, MasNowPaymentsService $service)
    {
        $transaction = $this->transactionForRequest($request, $uuid);

        if ($request->boolean('refresh') && !in_array($transaction->payment_status, ['finished', 'failed', 'refunded'], true)) {
            try {
                $transaction = $service->reconcileByUuid($uuid);
            } catch (\Throwable $e) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                    'status' => $transaction->payment_status,
                ], 422);
            }
        }

        return response()->json([
            'ok' => true,
            'status' => $transaction->payment_status,
            'actually_paid' => (float) $transaction->actually_paid,
            'pay_amount' => (float) $transaction->pay_amount,
            'pay_currency' => strtoupper((string) $transaction->pay_currency),
            'completed' => !empty($transaction->completed_at),
            'success_url' => $transaction->success_url,
            'expires_at' => $transaction->expires_at,
        ]);
    }

    public function recheck(Request $request, string $uuid, MasNowPaymentsService $service)
    {
        $this->transactionForRequest($request, $uuid);
        try {
            $transaction = $service->reconcileByUuid($uuid);
            return back()->with('success', 'Payment status refreshed: ' . $transaction->payment_status);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function authorizeSource(Request $request, string $sourceType, int $sourceId): void
    {
        $isAdmin = Auth::check() && optional(Auth::user()->role)->name === 'administrator';

        if ($sourceType === 'extension_plan') {
            abort_unless(Auth::check(), 403);
            return;
        }

        if ($sourceType !== 'service_order_payment') {
            abort(403);
        }

        abort_unless(Schema::hasTable('service_order_payments') && Schema::hasTable('service_orders'), 404);
        $payment = DB::table('service_order_payments')->where('id', $sourceId)->first();
        abort_unless($payment, 404);
        $order = DB::table('service_orders')->where('id', $payment->order_id)->first();
        abort_unless($order, 404);

        if ($isAdmin) {
            return;
        }

        if (Auth::check() && !empty($order->user_id) && (int) $order->user_id === (int) Auth::id()) {
            return;
        }

        $provided = (string) $request->input('payment_token', '');
        $stored = (string) ($payment->payment_token ?? '');
        abort_unless($stored !== '' && hash_equals($stored, $provided), 403);
    }

    private function transactionForRequest(Request $request, string $uuid): object
    {
        $transaction = DB::table('mas_payment_transactions')->where('uuid', $uuid)->first();
        abort_unless($transaction, 404);

        $isAdmin = Auth::check() && optional(Auth::user()->role)->name === 'administrator';
        $isOwner = Auth::check() && $transaction->user_id && (int) $transaction->user_id === (int) Auth::id();
        $provided = (string) $request->query('access', $request->input('access', ''));
        $hasAccessToken = $provided !== '' && !empty($transaction->access_token)
            && hash_equals((string) $transaction->access_token, hash('sha256', $provided));

        abort_unless($isAdmin || $isOwner || $hasAccessToken, 403);
        return $transaction;
    }
}
PHPFILE;

    $adminController = <<<'PHPFILE'
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MasNowPaymentsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MasPaymentGatewayController extends Controller
{
    public function gateways(MasNowPaymentsService $service)
    {
        $gateway = $service->gateway();
        $config = $service->config();
        $transactions = DB::table('mas_payment_transactions')->orderByDesc('id')->paginate(30);

        return view('mas_payments.admin', compact('gateway', 'config', 'transactions'));
    }

    public function save(Request $request, MasNowPaymentsService $service)
    {
        $data = $request->validate([
            'enabled' => 'nullable|boolean',
            'api_key' => 'nullable|string|max:500',
            'ipn_secret' => 'nullable|string|max:500',
            'base_url' => 'required|url|max:500',
            'price_currency' => 'required|string|max:10',
            'pay_currency' => 'required|string|max:40',
            'payout_currency' => 'required|string|max:40',
            'fixed_rate' => 'nullable|boolean',
            'fee_paid_by_user' => 'nullable|boolean',
            'underpayment_tolerance' => 'required|numeric|min:0|max:20',
            'payment_expiry_minutes' => 'required|integer|min:10|max:1440',
        ]);

        $data['enabled'] = $request->boolean('enabled');
        $data['fixed_rate'] = $request->boolean('fixed_rate');
        $data['fee_paid_by_user'] = $request->boolean('fee_paid_by_user');
        $service->saveConfig($data);

        return back()->with('success', 'NOWPayments settings saved securely.');
    }

    public function test(MasNowPaymentsService $service)
    {
        try {
            $service->testConnection();
            return back()->with('success', 'NOWPayments API connection is working.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
PHPFILE;

    $webhookController = <<<'PHPFILE'
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MasNowPaymentsService;
use Illuminate\Http\Request;

class MasNowPaymentsWebhookController extends Controller
{
    public function handle(Request $request, MasNowPaymentsService $service)
    {
        $signature = (string) $request->header('x-nowpayments-sig', '');
        $payload = $request->json()->all();

        try {
            $transaction = $service->processWebhook($payload, $signature);

            return response()->json([
                'ok' => true,
                'transaction' => $transaction->uuid,
                'status' => $transaction->payment_status,
            ]);
        } catch (\Throwable $e) {
            $code = str_contains(strtolower($e->getMessage()), 'signature') ? 401 : 422;
            return response()->json(['ok' => false, 'message' => $e->getMessage()], $code);
        }
    }
}
PHPFILE;

    $adminView = <<<'BLADE'
@extends('layouts.admin')

@section('content')
<style>
.mas-pay-wrap{max-width:1180px;margin:0 auto}.mas-pay-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:22px}.mas-pay-head h1{margin:0 0 6px;font-size:29px}.mas-pay-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(300px,.75fr);gap:18px}.mas-pay-card{background:#fff;border:1px solid #e5e7eb;border-radius:17px;box-shadow:0 8px 28px rgba(15,23,42,.05);overflow:hidden}.mas-pay-card-head{padding:18px 20px;border-bottom:1px solid #edf0f4;display:flex;justify-content:space-between;gap:15px;align-items:center}.mas-pay-card-body{padding:20px}.mas-pay-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.mas-pay-field label{display:block;margin-bottom:7px;color:#253047;font-weight:700}.mas-pay-field input,.mas-pay-field select{width:100%;padding:10px 12px;border:1px solid #ccd5e1;border-radius:9px;background:#fff}.mas-pay-wide{grid-column:1/-1}.mas-pay-switch{display:flex;align-items:center;gap:9px}.mas-pay-switch input{width:auto}.mas-pay-help{font-size:12px;color:#64748b;margin-top:6px;line-height:1.5}.mas-pay-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}.mas-pay-status{padding:12px 14px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;margin-bottom:10px}.mas-pay-table{width:100%;border-collapse:collapse}.mas-pay-table th,.mas-pay-table td{padding:12px 10px;border-bottom:1px solid #edf0f4;text-align:left;font-size:13px;vertical-align:top}.mas-pay-pill{display:inline-flex;padding:5px 9px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:800;text-transform:uppercase}.mas-pay-secret{font-family:monospace;color:#64748b}.mas-pay-callback{word-break:break-all;background:#0f172a;color:#dbeafe;padding:12px;border-radius:10px;font-size:12px}@media(max-width:900px){.mas-pay-grid{grid-template-columns:1fr}.mas-pay-form{grid-template-columns:1fr}.mas-pay-wide{grid-column:auto}.mas-pay-head{display:block}.mas-pay-table-wrap{overflow:auto}}
</style>

<div class="container-fluid">
<div class="mas-pay-wrap">
    <div class="mas-pay-head">
        <div>
            <h1>Global Payments</h1>
            <p class="text-muted mb-0">Manage NOWPayments once for Plugins, Extensions and Service Orders.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ url('/admin/payments/gateways') }}?tab=transactions">Transactions</a>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    @if(request('tab') === 'transactions')
        <div class="mas-pay-card">
            <div class="mas-pay-card-head"><strong>NOWPayments Transactions</strong><a href="{{ url('/admin/payments/gateways') }}">Gateway Settings</a></div>
            <div class="mas-pay-table-wrap">
                <table class="mas-pay-table">
                    <thead><tr><th>Created</th><th>Reference</th><th>Source</th><th>Price</th><th>Crypto</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse($transactions as $tx)
                        <tr>
                            <td>{{ $tx->created_at }}</td>
                            <td><strong>{{ $tx->source_reference ?: $tx->provider_order_id }}</strong><br><small>{{ $tx->provider_payment_id }}</small></td>
                            <td>{{ str_replace('_',' ',ucwords($tx->source_type,'_')) }}</td>
                            <td>{{ strtoupper($tx->price_currency) }} {{ number_format((float)$tx->amount,2) }}</td>
                            <td>{{ number_format((float)$tx->pay_amount,8) }} {{ strtoupper($tx->pay_currency) }}<br><small>Paid: {{ number_format((float)$tx->actually_paid,8) }}</small></td>
                            <td><span class="mas-pay-pill">{{ $tx->payment_status }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No crypto transactions yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $transactions->appends(['tab'=>'transactions'])->links() }}</div>
        </div>
    @else
    <div class="mas-pay-grid">
        <form method="post" action="{{ url('/admin/payments/gateways/nowpayments') }}" class="mas-pay-card">
            @csrf
            <div class="mas-pay-card-head">
                <div><strong>NOWPayments</strong><div class="text-muted small">White-label QR and wallet-address checkout</div></div>
                <span class="mas-pay-pill">{{ optional($gateway)->enabled ? 'Enabled' : 'Disabled' }}</span>
            </div>
            <div class="mas-pay-card-body">
                <div class="mas-pay-form">
                    <div class="mas-pay-field mas-pay-wide"><label class="mas-pay-switch"><input type="checkbox" name="enabled" value="1" {{ optional($gateway)->enabled ? 'checked' : '' }}> Enable NOWPayments globally</label></div>
                    <div class="mas-pay-field mas-pay-wide"><label>API Key</label><input type="password" name="api_key" autocomplete="new-password" placeholder="Leave blank to keep the saved key"><div class="mas-pay-help">Saved encrypted. Existing value: <span class="mas-pay-secret">{{ !empty($config['api_key']) ? '•••••••• configured' : 'not configured' }}</span></div></div>
                    <div class="mas-pay-field mas-pay-wide"><label>IPN Secret</label><input type="password" name="ipn_secret" autocomplete="new-password" placeholder="Leave blank to keep the saved secret"><div class="mas-pay-help">Use the secret generated under Instant Payment Notifications.</div></div>
                    <div class="mas-pay-field mas-pay-wide"><label>API Base URL</label><input name="base_url" value="{{ old('base_url',$config['base_url']) }}" required></div>
                    <div class="mas-pay-field"><label>Price Currency</label><input name="price_currency" value="{{ old('price_currency',$config['price_currency']) }}" required></div>
                    <div class="mas-pay-field"><label>Pay Currency</label><input name="pay_currency" value="{{ old('pay_currency',$config['pay_currency']) }}" required></div>
                    <div class="mas-pay-field"><label>Payout Currency</label><input name="payout_currency" value="{{ old('payout_currency',$config['payout_currency']) }}" required></div>
                    <div class="mas-pay-field"><label>Payment Expiry (minutes)</label><input type="number" min="10" max="1440" name="payment_expiry_minutes" value="{{ old('payment_expiry_minutes',$config['payment_expiry_minutes']) }}" required></div>
                    <div class="mas-pay-field"><label>Underpayment Tolerance (%)</label><input type="number" step="0.01" min="0" max="20" name="underpayment_tolerance" value="{{ old('underpayment_tolerance',$config['underpayment_tolerance']) }}" required></div>
                    <div class="mas-pay-field"><label class="mas-pay-switch"><input type="checkbox" name="fixed_rate" value="1" {{ !empty($config['fixed_rate']) ? 'checked' : '' }}> Fixed rate</label></div>
                    <div class="mas-pay-field"><label class="mas-pay-switch"><input type="checkbox" name="fee_paid_by_user" value="1" {{ !empty($config['fee_paid_by_user']) ? 'checked' : '' }}> Customer pays fee</label></div>
                </div>
                <div class="mas-pay-actions"><button class="btn btn-primary" type="submit">Save Gateway</button></div>
            </div>
        </form>

        <div>
            <div class="mas-pay-card mb-3"><div class="mas-pay-card-head"><strong>Connection</strong></div><div class="mas-pay-card-body"><div class="mas-pay-status">Last test: <strong>{{ optional($gateway)->last_test_status ?: 'Not tested' }}</strong><br><small>{{ optional($gateway)->last_tested_at }}</small></div><form method="post" action="{{ url('/admin/payments/gateways/nowpayments/test') }}">@csrf<button class="btn btn-outline-primary" type="submit">Test API Connection</button></form></div></div>
            <div class="mas-pay-card"><div class="mas-pay-card-head"><strong>Webhook URL</strong></div><div class="mas-pay-card-body"><p class="text-muted small">Use this callback for every payment. The create-payment request also sends it automatically.</p><div class="mas-pay-callback">{{ url('/api/webhooks/nowpayments') }}</div><div class="mas-pay-help mt-3">Only a valid HMAC SHA-512 signature and a server-confirmed <strong>finished</strong> status can complete an order.</div></div></div>
        </div>
    </div>
    @endif
</div>
</div>
@endsection
BLADE;

    $payView = <<<'BLADE'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>Crypto Payment | MAKE ANYTHING SIMPLE</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#050608;color:#fff;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.np-wrap{width:min(1080px,calc(100% - 28px));margin:45px auto}.np-brand{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}.np-logo{font-weight:900;letter-spacing:.04em}.np-secure{color:#9ca3af;font-size:13px}.np-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(320px,.85fr);gap:20px}.np-card{border:1px solid rgba(255,255,255,.13);border-radius:24px;background:linear-gradient(145deg,rgba(255,255,255,.055),rgba(255,255,255,.018));overflow:hidden}.np-main{padding:32px}.np-kicker{color:#9ab9ff;font-size:12px;font-weight:800;letter-spacing:.16em;text-transform:uppercase}.np-main h1{font-size:clamp(34px,6vw,63px);line-height:1;margin:13px 0 15px;letter-spacing:-.05em}.np-lead{color:rgba(255,255,255,.62);line-height:1.7}.np-amount-box{margin:26px 0;padding:19px;border:1px solid rgba(255,255,255,.12);border-radius:17px;background:rgba(0,0,0,.22)}.np-amount-label{color:#8f98a7;font-size:12px;text-transform:uppercase;letter-spacing:.1em}.np-amount{margin-top:7px;font-size:31px;font-weight:900}.np-network{margin-top:8px;color:#aab3c0;font-size:13px}.np-address-label{margin-top:22px;color:#8f98a7;font-size:12px;text-transform:uppercase;letter-spacing:.1em}.np-address{margin-top:8px;padding:14px;border-radius:13px;background:#0b0d12;border:1px solid rgba(255,255,255,.12);word-break:break-all;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;line-height:1.6}.np-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:15px}.np-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 17px;border-radius:12px;border:1px solid rgba(255,255,255,.2);background:transparent;color:#fff;text-decoration:none;font-weight:800;cursor:pointer}.np-btn.primary{background:#fff;color:#050505;border-color:#fff}.np-side{padding:26px}.np-qr-shell{display:grid;place-items:center;min-height:310px;background:#fff;border-radius:19px;padding:20px}.np-qr-shell canvas,.np-qr-shell img{max-width:100%;height:auto}.np-status{margin-top:17px;padding:17px;border:1px solid rgba(255,255,255,.13);border-radius:16px}.np-status-top{display:flex;align-items:center;gap:10px}.np-dot{width:10px;height:10px;border-radius:50%;background:#facc15;box-shadow:0 0 0 7px rgba(250,204,21,.12)}.np-status-title{font-weight:900}.np-status-text{margin-top:8px;color:#9ca3af;font-size:13px;line-height:1.6}.np-progress{display:grid;grid-template-columns:repeat(4,1fr);gap:5px;margin-top:18px}.np-progress span{height:4px;border-radius:999px;background:rgba(255,255,255,.1)}.np-progress span.active{background:#8db2ff}.np-warning{margin-top:16px;padding:14px;border-radius:14px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.22);color:#f5d08a;font-size:12px;line-height:1.6}.np-footer{margin-top:22px;text-align:center;color:#717987;font-size:12px}.np-flash{padding:13px 15px;border-radius:12px;margin-bottom:15px}.np-flash.ok{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3)}.np-flash.bad{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3)}@media(max-width:820px){.np-grid{grid-template-columns:1fr}.np-wrap{margin:22px auto}.np-main,.np-side{padding:22px}.np-brand{align-items:flex-start}.np-qr-shell{min-height:270px}}
</style>
</head>
<body>
<div class="np-wrap">
    <div class="np-brand"><div class="np-logo">MAS™</div><div class="np-secure">Secure blockchain payment</div></div>
    @if(session('success'))<div class="np-flash ok">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="np-flash bad">{{ session('error') }}</div>@endif
    <div class="np-grid">
        <section class="np-card np-main">
            <div class="np-kicker">Pay with crypto</div>
            <h1>{{ $transaction->order_description }}</h1>
            <p class="np-lead">Send the exact amount using the exact network shown below. The order completes automatically after blockchain confirmation.</p>
            <div class="np-amount-box"><div class="np-amount-label">Exact amount</div><div class="np-amount" id="payAmount">{{ rtrim(rtrim(number_format((float)$transaction->pay_amount,12,'.',''),'0'),'.') }} {{ strtoupper($transaction->pay_currency) }}</div><div class="np-network">Network/currency code: {{ strtoupper($transaction->pay_currency) }}</div></div>
            <div class="np-address-label">Payment address</div><div class="np-address" id="payAddress">{{ $transaction->pay_address }}</div>
            <div class="np-actions"><button class="np-btn primary" type="button" onclick="copyText('payAddress',this)">Copy Address</button><button class="np-btn" type="button" onclick="copyAmount(this)">Copy Amount</button><form method="post" action="{{ route('mas.nowpayments.recheck',['uuid'=>$transaction->uuid,'access'=>request('access')]) }}">@csrf<button class="np-btn" type="submit">Refresh Status</button></form><a class="np-btn" href="{{ $transaction->cancel_url }}">Choose Another Method</a></div>
            <div class="np-warning">Only send <strong>{{ strtoupper($transaction->pay_currency) }}</strong> using its matching network. Sending another asset or network can permanently lose funds.</div>
        </section>
        <aside class="np-card np-side">
            <div class="np-qr-shell"><div id="qrcode"></div></div>
            <div class="np-status"><div class="np-status-top"><span class="np-dot" id="statusDot"></span><span class="np-status-title" id="statusTitle">{{ ucfirst(str_replace('_',' ',$transaction->payment_status)) }}</span></div><div class="np-status-text" id="statusText">Waiting for NOWPayments status update…</div><div class="np-progress" id="progress"><span></span><span></span><span></span><span></span></div></div>
        </aside>
    </div>
    <div class="np-footer">Payment ID: {{ $transaction->provider_payment_id }} · Order: {{ $transaction->provider_order_id }}</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
(function(){
    var address=@json($transaction->pay_address), amount=@json(rtrim(rtrim(number_format((float)$transaction->pay_amount,12,'.',''),'0'),'.')), currency=@json(strtoupper($transaction->pay_currency));
    if(window.QRCode){new QRCode(document.getElementById('qrcode'),{text:address,width:250,height:250,correctLevel:QRCode.CorrectLevel.M});}
    window.copyText=function(id,btn){navigator.clipboard.writeText(document.getElementById(id).textContent.trim());var old=btn.textContent;btn.textContent='Copied';setTimeout(function(){btn.textContent=old},1200)};
    window.copyAmount=function(btn){navigator.clipboard.writeText(amount);var old=btn.textContent;btn.textContent='Copied';setTimeout(function(){btn.textContent=old},1200)};
    var titles={waiting:'Waiting for payment',confirming:'Payment detected',confirmed:'Blockchain confirmed',sending:'Processing settlement',finished:'Payment completed',partially_paid:'Additional payment required',expired:'Payment expired',failed:'Payment failed',refunded:'Payment refunded'};
    var messages={waiting:'Open your wallet and send the exact amount.',confirming:'The transfer was detected and is receiving confirmations.',confirmed:'Blockchain confirmations are complete.',sending:'NOWPayments is processing settlement.',finished:'Payment is complete. Redirecting to your purchase…',partially_paid:'The received amount is lower than required.',expired:'This payment address has expired. Start a new payment.',failed:'The payment could not be completed.',refunded:'This transaction was refunded.'};
    var level={waiting:1,confirming:2,confirmed:3,sending:3,finished:4,partially_paid:2,expired:0,failed:0,refunded:0};
    function paint(data){var s=data.status||'waiting';document.getElementById('statusTitle').textContent=titles[s]||s;document.getElementById('statusText').textContent=messages[s]||'Payment status updated.';var dots=document.querySelectorAll('#progress span');dots.forEach(function(x,i){x.classList.toggle('active',i<(level[s]||0))});var dot=document.getElementById('statusDot');if(s==='finished'){dot.style.background='#22c55e';dot.style.boxShadow='0 0 0 7px rgba(34,197,94,.12)'}else if(['failed','expired','refunded'].indexOf(s)>=0){dot.style.background='#ef4444';dot.style.boxShadow='0 0 0 7px rgba(239,68,68,.12)'}if(data.completed&&data.success_url){setTimeout(function(){window.location.href=data.success_url},1800)}}
    function poll(refresh){var u=@json(route('mas.nowpayments.status',['uuid'=>$transaction->uuid]))+'?access='+encodeURIComponent(@json(request('access','')))+(refresh?'&refresh=1':'');fetch(u,{headers:{'Accept':'application/json'}}).then(function(r){return r.json()}).then(paint).catch(function(){});}
    poll(true);setInterval(function(){poll(true)},5000);
})();
</script>
</body>
</html>
BLADE;

    return [
        'app/Services/MasNowPaymentsService.php' => $service,
        'app/Http/Controllers/MasNowPaymentsController.php' => $publicController,
        'app/Http/Controllers/Admin/MasPaymentGatewayController.php' => $adminController,
        'app/Http/Controllers/Api/MasNowPaymentsWebhookController.php' => $webhookController,
        'resources/views/mas_payments/admin.blade.php' => $adminView,
        'resources/views/mas_payments/pay.blade.php' => $payView,
    ];
}

function masnp_web_routes(): string
{
    return <<<'PHPROUTES'
// MAS_GLOBAL_NOWPAYMENTS_WEB_ROUTES_START
\Illuminate\Support\Facades\Route::middleware(['admin'])->prefix('admin/payments')->group(function () {
    \Illuminate\Support\Facades\Route::get('/gateways', [\App\Http\Controllers\Admin\MasPaymentGatewayController::class, 'gateways'])->name('mas.payments.gateways');
    \Illuminate\Support\Facades\Route::post('/gateways/nowpayments', [\App\Http\Controllers\Admin\MasPaymentGatewayController::class, 'save'])->name('mas.payments.nowpayments.save');
    \Illuminate\Support\Facades\Route::post('/gateways/nowpayments/test', [\App\Http\Controllers\Admin\MasPaymentGatewayController::class, 'test'])->name('mas.payments.nowpayments.test');
});

\Illuminate\Support\Facades\Route::post('/payments/nowpayments/create', [\App\Http\Controllers\MasNowPaymentsController::class, 'create'])->name('mas.nowpayments.create');
\Illuminate\Support\Facades\Route::get('/payments/nowpayments/{uuid}', [\App\Http\Controllers\MasNowPaymentsController::class, 'show'])->name('mas.nowpayments.show');
\Illuminate\Support\Facades\Route::get('/payments/nowpayments/{uuid}/status', [\App\Http\Controllers\MasNowPaymentsController::class, 'status'])->name('mas.nowpayments.status');
\Illuminate\Support\Facades\Route::post('/payments/nowpayments/{uuid}/recheck', [\App\Http\Controllers\MasNowPaymentsController::class, 'recheck'])->name('mas.nowpayments.recheck');
// MAS_GLOBAL_NOWPAYMENTS_WEB_ROUTES_END
PHPROUTES;
}

function masnp_api_routes(): string
{
    return <<<'PHPROUTES'
// MAS_GLOBAL_NOWPAYMENTS_API_ROUTES_START
\Illuminate\Support\Facades\Route::post('/webhooks/nowpayments', [\App\Http\Controllers\Api\MasNowPaymentsWebhookController::class, 'handle'])->name('mas.nowpayments.webhook');
// MAS_GLOBAL_NOWPAYMENTS_API_ROUTES_END
PHPROUTES;
}

function masnp_plugin_button(): string
{
    return <<<'BLADE'
{{-- MAS_NOWPAYMENTS_CHECKOUT_BUTTON_START --}}
@if(isset($order) && \Illuminate\Support\Facades\Schema::hasTable('service_order_payments') && class_exists(\App\Services\MasNowPaymentsService::class))
    @php
        $masNpDuePayment = \Illuminate\Support\Facades\DB::table('service_order_payments')
            ->where('order_id', $order->id)
            ->whereNotIn('status', ['verified','paid'])
            ->orderByDesc('id')
            ->first();
        $masNpGatewayEnabled = app(\App\Services\MasNowPaymentsService::class)->enabled();
    @endphp
    @if($masNpGatewayEnabled && $masNpDuePayment)
        <div style="margin-top:18px;padding:20px;border:1px solid rgba(124,167,255,.28);border-radius:18px;background:rgba(124,167,255,.07)">
            <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap">
                <div><strong style="display:block;font-size:17px">Pay with Crypto</strong><span style="display:block;margin-top:5px;color:#7c8594;font-size:13px">USDT TRC20 QR/address with automatic blockchain verification.</span></div>
                <form method="post" action="{{ route('mas.nowpayments.create') }}" style="margin:0">
                    @csrf
                    <input type="hidden" name="source_type" value="service_order_payment">
                    <input type="hidden" name="source_id" value="{{ $masNpDuePayment->id }}">
                    <input type="hidden" name="payment_token" value="{{ $masNpDuePayment->payment_token ?? '' }}">
                    <button type="submit" style="border:0;border-radius:999px;padding:12px 19px;background:#fff;color:#050505;font-weight:800;cursor:pointer">Pay with Crypto →</button>
                </form>
            </div>
        </div>
    @endif
@endif
{{-- MAS_NOWPAYMENTS_CHECKOUT_BUTTON_END --}}
BLADE;
}

function masnp_schema(string $root): array
{
    masnp_boot($root);
    $created = [];

    if (!\Illuminate\Support\Facades\Schema::hasTable('mas_payment_gateways')) {
        \Illuminate\Support\Facades\Schema::create('mas_payment_gateways', function ($table) {
            $table->bigIncrements('id');
            $table->string('provider', 80)->unique();
            $table->string('display_name', 120);
            $table->boolean('enabled')->default(false);
            $table->longText('config_encrypted')->nullable();
            $table->dateTime('last_tested_at')->nullable();
            $table->string('last_test_status', 30)->nullable();
            $table->timestamps();
        });
        $created[] = 'mas_payment_gateways';
    }

    if (!\Illuminate\Support\Facades\Schema::hasTable('mas_payment_transactions')) {
        \Illuminate\Support\Facades\Schema::create('mas_payment_transactions', function ($table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('access_token', 64)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('source_type', 60)->index();
            $table->unsignedBigInteger('source_id')->index();
            $table->string('source_reference', 191)->nullable();
            $table->string('provider', 80)->index();
            $table->string('provider_payment_id', 191)->nullable()->unique();
            $table->string('provider_order_id', 191)->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->decimal('amount', 18, 8);
            $table->string('price_currency', 20);
            $table->decimal('pay_amount', 24, 12)->nullable();
            $table->string('pay_currency', 40)->nullable();
            $table->decimal('actually_paid', 24, 12)->default(0);
            $table->text('pay_address')->nullable();
            $table->string('payment_status', 40)->default('waiting')->index();
            $table->string('order_description', 500)->nullable();
            $table->text('success_url')->nullable();
            $table->text('cancel_url')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->longText('raw_response')->nullable();
            $table->longText('raw_webhook')->nullable();
            $table->timestamps();
        });
        $created[] = 'mas_payment_transactions';
    }

    if (!\Illuminate\Support\Facades\Schema::hasTable('mas_payment_events')) {
        \Illuminate\Support\Facades\Schema::create('mas_payment_events', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transaction_id')->index();
            $table->string('event_type', 60);
            $table->string('status', 40)->nullable();
            $table->longText('payload')->nullable();
            $table->dateTime('created_at')->nullable();
        });
        $created[] = 'mas_payment_events';
    }

    $defaults = [
        'api_key' => '', 'ipn_secret' => '', 'base_url' => 'https://api.nowpayments.io/v1',
        'price_currency' => 'usd', 'pay_currency' => 'usdttrc20', 'payout_currency' => 'usdttrc20',
        'fixed_rate' => true, 'fee_paid_by_user' => false, 'underpayment_tolerance' => 1.0,
        'payment_expiry_minutes' => 60,
    ];

    if (!\Illuminate\Support\Facades\DB::table('mas_payment_gateways')->where('provider', 'nowpayments')->exists()) {
        \Illuminate\Support\Facades\DB::table('mas_payment_gateways')->insert([
            'provider' => 'nowpayments',
            'display_name' => 'NOWPayments',
            'enabled' => false,
            'config_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($defaults, JSON_UNESCAPED_SLASHES)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $created;
}

function masnp_install(string $root): array
{
    $payload = masnp_payload();
    $webRoutePath = $root . '/routes/web.php';
    $apiRoutePath = $root . '/routes/api.php';
    $adminLayoutPath = $root . '/resources/views/layouts/admin.blade.php';

    foreach ([$webRoutePath, $apiRoutePath, $adminLayoutPath] as $required) {
        if (!is_file($required)) {
            throw new RuntimeException('Required application file is missing: ' . $required);
        }
    }

    $pluginCandidates = [
        'resources/views/mas_plugins/payment.blade.php',
        'resources/views/mas_plugins/purchase.blade.php',
        'resources/views/mas_plugins/checkout-payment.blade.php',
    ];

    $touched = array_keys($payload);
    $touched[] = 'routes/web.php';
    $touched[] = 'routes/api.php';
    $touched[] = 'resources/views/layouts/admin.blade.php';
    foreach ($pluginCandidates as $candidate) {
        if (is_file($root . '/' . $candidate)) {
            $touched[] = $candidate;
        }
    }
    $touched = array_values(array_unique($touched));

    $backupDirectory = $root . '/storage/app/mas_installer_backups/nowpayments_' . date('Ymd_His');
    $manifest = [];

    foreach ($touched as $relative) {
        $source = $root . '/' . $relative;
        $manifest[$relative] = ['existed' => is_file($source)];
        if (is_file($source)) {
            $backup = $backupDirectory . '/' . $relative;
            if (!is_dir(dirname($backup)) && !@mkdir(dirname($backup), 0775, true) && !is_dir(dirname($backup))) {
                throw new RuntimeException('Could not create backup directory for ' . $relative);
            }
            if (!@copy($source, $backup)) {
                throw new RuntimeException('Could not back up ' . $relative);
            }
        }
    }
    masnp_write_json($backupDirectory . '/manifest.json', $manifest);

    foreach ($payload as $relative => $contents) {
        masnp_write_file($root . '/' . $relative, $contents);
    }

    $webRoutes = (string) file_get_contents($webRoutePath);
    $webRoutes = masnp_append_marker(
        $webRoutes,
        '// MAS_GLOBAL_NOWPAYMENTS_WEB_ROUTES_START',
        '// MAS_GLOBAL_NOWPAYMENTS_WEB_ROUTES_END',
        masnp_web_routes()
    );
    masnp_write_file($webRoutePath, $webRoutes);

    $apiRoutes = (string) file_get_contents($apiRoutePath);
    $apiRoutes = masnp_append_marker(
        $apiRoutes,
        '// MAS_GLOBAL_NOWPAYMENTS_API_ROUTES_START',
        '// MAS_GLOBAL_NOWPAYMENTS_API_ROUTES_END',
        masnp_api_routes()
    );
    masnp_write_file($apiRoutePath, $apiRoutes);

    $adminLayout = masnp_patch_admin_menu((string) file_get_contents($adminLayoutPath));
    masnp_write_file($adminLayoutPath, $adminLayout);

    $patchedPluginViews = [];
    foreach ($pluginCandidates as $candidate) {
        $path = $root . '/' . $candidate;
        if (!is_file($path)) {
            continue;
        }
        $before = (string) file_get_contents($path);
        $after = masnp_insert_content_block($before, masnp_plugin_button());
        if ($after !== $before) {
            masnp_write_file($path, $after);
            $patchedPluginViews[] = $candidate;
        }
    }

    $createdTables = masnp_schema($root);
    $cacheRemoved = masnp_clear_cache($root);

    $state = [
        'version' => MAS_NP_INSTALLER_VERSION,
        'installed_at' => date(DATE_ATOM),
        'backup_directory' => $backupDirectory,
        'manifest' => $manifest,
        'created_tables' => $createdTables,
        'patched_plugin_views' => $patchedPluginViews,
        'cache_removed' => $cacheRemoved,
    ];
    masnp_write_json(masnp_state_path($root), $state);

    return [
        'message' => 'Global NOWPayments gateway installed successfully.',
        'created_tables' => $createdTables,
        'patched_plugin_views' => $patchedPluginViews,
        'cache_removed' => $cacheRemoved,
    ];
}

function masnp_rollback(string $root): array
{
    $state = masnp_read_state($root);
    if (empty($state['backup_directory']) || empty($state['manifest']) || !is_array($state['manifest'])) {
        throw new RuntimeException('No rollback backup was found.');
    }

    $backupDirectory = (string) $state['backup_directory'];
    foreach ($state['manifest'] as $relative => $meta) {
        $destination = $root . '/' . $relative;
        if (!empty($meta['existed'])) {
            $backup = $backupDirectory . '/' . $relative;
            if (!is_file($backup)) {
                throw new RuntimeException('Backup file is missing: ' . $relative);
            }
            masnp_write_file($destination, (string) file_get_contents($backup));
        } elseif (is_file($destination)) {
            if (!@unlink($destination)) {
                throw new RuntimeException('Could not remove installed file: ' . $relative);
            }
        }
    }

    masnp_clear_cache($root);
    @unlink(masnp_state_path($root));

    return ['message' => 'NOWPayments code and UI were rolled back. Payment database records were preserved for audit safety.'];
}

$root = '';
$state = [];
$result = null;
$error = '';

try {
    $root = masnp_root();
    $state = masnp_read_state($root);
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';

    if ($action === 'rollback') {
        $result = masnp_rollback($root);
        $state = [];
    } elseif ($action === 'repair') {
        $result = masnp_install($root);
        $state = masnp_read_state($root);
    } elseif (empty($state)) {
        $result = masnp_install($root);
        $state = masnp_read_state($root);
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$installed = !empty($state) && ($state['version'] ?? '') === MAS_NP_INSTALLER_VERSION;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo masnp_h(MAS_NP_INSTALLER_NAME); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#07090d;color:#eaf0f7;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{width:min(920px,calc(100% - 28px));margin:42px auto}.card{background:#11151c;border:1px solid #29313d;border-radius:22px;padding:28px;box-shadow:0 20px 70px rgba(0,0,0,.3)}.tag{display:inline-flex;padding:7px 11px;border-radius:999px;background:#1c2635;color:#9dc1ff;font-size:12px;font-weight:800;letter-spacing:.08em}h1{margin:16px 0 8px;font-size:34px}p{color:#aab5c3;line-height:1.65}.status{margin:22px 0;padding:16px;border-radius:14px;background:#0b0e13;border:1px solid #27303b}.ok{border-color:#245e42;color:#aef2cd}.bad{border-color:#7b3030;color:#ffc4c4}.buttons{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}button,a.btn{appearance:none;border:1px solid #3b4656;background:#1a2130;color:#fff;border-radius:12px;padding:12px 16px;text-decoration:none;font-weight:800;cursor:pointer}button.primary,a.primary{background:#fff;color:#050505;border-color:#fff}code{word-break:break-word;color:#c8d8ee}ul{color:#b7c1ce;line-height:1.85}small{color:#7f8a99}.warning{padding:14px;border-radius:12px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.22);color:#f5d08a}
</style>
</head>
<body><div class="wrap"><div class="card">
<span class="tag">VERSION <?php echo masnp_h(MAS_NP_INSTALLER_VERSION); ?></span>
<h1><?php echo masnp_h(MAS_NP_INSTALLER_NAME); ?></h1>
<p>Installs NOWPayments as a global encrypted payment gateway with QR/address checkout, signed IPN verification, server-side status confirmation and automatic fulfilment hooks.</p>

<?php if ($error !== ''): ?><div class="status bad"><strong>Installation error</strong><br><?php echo masnp_h($error); ?></div>
<?php elseif (is_array($result)): ?><div class="status ok"><strong><?php echo masnp_h($result['message'] ?? 'Action completed.'); ?></strong></div><?php endif; ?>

<div class="status"><strong>Status:</strong> <?php echo $installed ? 'Installed' : 'Not installed'; ?><br><strong>Laravel root:</strong> <code><?php echo masnp_h($root); ?></code></div>

<?php if ($installed): ?>
<ul>
<li>Admin: <code>/admin/payments/gateways</code></li>
<li>Webhook: <code>/api/webhooks/nowpayments</code></li>
<li>Credentials are encrypted with the Laravel application key</li>
<li>Plugin Purchase fulfilment uses the existing MasPluginPurchaseService</li>
<li>Extension plans can use source type <code>extension_plan</code></li>
<li>Existing Stripe/manual payment methods are preserved</li>
</ul>
<div class="warning">Open Payments → Gateways, save the API Key and IPN Secret, test the connection, then enable the gateway. Never place credentials inside this installer.</div>
<?php endif; ?>

<div class="buttons">
<form method="post"><input type="hidden" name="action" value="repair"><button class="primary" type="submit">Repair / install again</button></form>
<?php if ($installed): ?><form method="post" onsubmit="return confirm('Restore the files from the latest automatic backup? Payment database records will be preserved.');"><input type="hidden" name="action" value="rollback"><button type="submit">Rollback latest update</button></form><?php endif; ?>
<a class="btn" href="/admin/payments/gateways" target="_blank" rel="noopener">Open Payment Settings</a>
</div>
<p><small>After testing, delete this installer from the public directory because it has no access token.</small></p>
</div></div></body></html>
