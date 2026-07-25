<?php
/**
 * MAS Global NOWPayments Gateway
 * Version: 1.0.1
 *
 * Upload this file to Laravel public/ and open it in a browser.
 * It creates an encrypted global NOWPayments gateway, payment page,
 * signed IPN webhook, transaction history, plugin-purchase integration,
 * automatic backup, repair, and rollback.
 *
 * IMPORTANT: Delete this public installer after testing.
 */
declare(strict_types=1);

const MAS_NP_INSTALLER_VERSION = '1.0.1';
const MAS_NP_INSTALLER_NAME = 'Global NOWPayments Gateway';
const MAS_NP_STATE_FILE = 'storage/app/mas_installer_states/mas-global-nowpayments-v1.0.1.json';

@set_time_limit(300);
@ini_set('display_errors', '1');
error_reporting(E_ALL);

function masnp_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function masnp_root(): string
{
    $candidates = [__DIR__, dirname(__DIR__), dirname(__DIR__, 2)];

    foreach ($candidates as $candidate) {
        if (is_file($candidate . '/artisan') && is_file($candidate . '/bootstrap/app.php')) {
            return realpath($candidate) ?: $candidate;
        }
    }

    throw new RuntimeException('Laravel root was not detected. Upload this installer inside the public directory.');
}

function masnp_state_path(string $root): string
{
    return $root . '/' . MAS_NP_STATE_FILE;
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

function masnp_write_atomic(string $path, string $contents): void
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
    masnp_write_atomic($path, $json);
}

function masnp_boot(string $root): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('vendor/autoload.php is missing. Run Composer install before using this installer.');
    }

    require_once $autoload;
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
    $pattern = '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/s';
    if (preg_match($pattern, $contents)) {
        $updated = preg_replace($pattern, $block, $contents, 1);
        if (!is_string($updated)) {
            throw new RuntimeException('Could not replace marker block: ' . $start);
        }
        return $updated;
    }

    return $contents;
}

function masnp_append_route_block(string $contents, string $start, string $end, string $block): string
{
    if (strpos($contents, $start) !== false) {
        return masnp_replace_marker($contents, $start, $end, $block);
    }

    return rtrim($contents) . "\n\n" . $block . "\n";
}

function masnp_patch_menu(string $contents, string $block): string
{
    $start = '{{-- MAS_GLOBAL_NOWPAYMENTS_MENU_START --}}';
    $end = '{{-- MAS_GLOBAL_NOWPAYMENTS_MENU_END --}}';

    if (strpos($contents, $start) !== false) {
        return masnp_replace_marker($contents, $start, $end, $block);
    }

    $knownNeedles = [
        '{{-- MAS_AI_CONTENT_ENGINE_MENU_START --}}',
        '{{-- MAS_SEPARATE_EXTENSIONS_MENU_START --}}',
        '{{-- MAS_SEPARATE_PLUGINS_MENU_START --}}',
    ];

    foreach ($knownNeedles as $needle) {
        $position = strpos($contents, $needle);
        if ($position !== false) {
            return substr($contents, 0, $position) . $block . "\n" . substr($contents, $position);
        }
    }

    $position = strripos($contents, '</ul>');
    if ($position === false) {
        throw new RuntimeException('Could not find a safe sidebar insertion point.');
    }

    return substr($contents, 0, $position) . $block . "\n" . substr($contents, $position);
}

function masnp_patch_plugin_payment_view(string $contents, string $block): string
{
    $start = '{{-- MAS_GLOBAL_NOWPAYMENTS_PLUGIN_BUTTON_START --}}';
    $end = '{{-- MAS_GLOBAL_NOWPAYMENTS_PLUGIN_BUTTON_END --}}';

    if (strpos($contents, $start) !== false) {
        return masnp_replace_marker($contents, $start, $end, $block);
    }

    $position = strrpos($contents, '@endsection');
    if ($position === false) {
        return $contents;
    }

    return substr($contents, 0, $position) . $block . "\n" . substr($contents, $position);
}

function masnp_create_tables(string $root): void
{
    masnp_boot($root);

    $schema = \Illuminate\Support\Facades\Schema::getFacadeRoot();
    if (!$schema) {
        throw new RuntimeException('Laravel Schema facade is unavailable.');
    }

    if (!\Illuminate\Support\Facades\Schema::hasTable('mas_payment_gateways')) {
        \Illuminate\Support\Facades\Schema::create('mas_payment_gateways', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('gateway_key', 80)->unique();
            $table->string('name', 150);
            $table->boolean('enabled')->default(false);
            $table->longText('config')->nullable();
            $table->longText('credentials')->nullable();
            $table->timestamps();
        });
    }

    if (!\Illuminate\Support\Facades\Schema::hasTable('mas_payment_transactions')) {
        \Illuminate\Support\Facades\Schema::create('mas_payment_transactions', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('uuid', 64)->unique();
            $table->string('public_token', 80)->unique();
            $table->string('gateway', 80)->default('nowpayments');
            $table->string('source_type', 100)->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('reference', 120)->unique();
            $table->string('description', 255)->nullable();
            $table->decimal('price_amount', 20, 8)->default(0);
            $table->string('price_currency', 20)->default('usd');
            $table->string('payment_id', 120)->nullable()->unique();
            $table->string('status', 50)->default('created')->index();
            $table->text('pay_address')->nullable();
            $table->decimal('pay_amount', 28, 12)->nullable();
            $table->string('pay_currency', 40)->nullable();
            $table->decimal('actually_paid', 28, 12)->nullable();
            $table->text('return_url')->nullable();
            $table->longText('raw_response')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id'], 'mas_payment_source_idx');
        });
    }

    $gateway = \Illuminate\Support\Facades\DB::table('mas_payment_gateways')
        ->where('gateway_key', 'nowpayments')
        ->first();

    if (!$gateway) {
        \Illuminate\Support\Facades\DB::table('mas_payment_gateways')->insert([
            'gateway_key' => 'nowpayments',
            'name' => 'NOWPayments',
            'enabled' => 0,
            'config' => json_encode([
                'base_url' => 'https://api.nowpayments.io/v1',
                'price_currency' => 'usd',
                'pay_currency' => 'usdttrc20',
                'payout_currency' => 'usdttrc20',
                'fixed_rate' => true,
                'fee_paid_by_user' => false,
                'underpayment_tolerance' => 1,
                'expiry_minutes' => 60,
            ], JSON_UNESCAPED_SLASHES),
            'credentials' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function masnp_payload(): array
{
    $gatewayModel = <<<'MAS_MODEL_GATEWAY'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasPaymentGateway extends Model
{
    protected $table = 'mas_payment_gateways';

    protected $fillable = [
        'gateway_key',
        'name',
        'enabled',
        'config',
        'credentials',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function configArray(): array
    {
        $decoded = json_decode((string) $this->config, true);
        return is_array($decoded) ? $decoded : [];
    }
}
MAS_MODEL_GATEWAY;

    $transactionModel = <<<'MAS_MODEL_TRANSACTION'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasPaymentTransaction extends Model
{
    protected $table = 'mas_payment_transactions';

    protected $fillable = [
        'uuid',
        'public_token',
        'gateway',
        'source_type',
        'source_id',
        'user_id',
        'reference',
        'description',
        'price_amount',
        'price_currency',
        'payment_id',
        'status',
        'pay_address',
        'pay_amount',
        'pay_currency',
        'actually_paid',
        'return_url',
        'raw_response',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'price_amount' => 'decimal:8',
        'pay_amount' => 'decimal:12',
        'actually_paid' => 'decimal:12',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
MAS_MODEL_TRANSACTION;

    $service = <<<'MAS_SERVICE'
<?php
namespace App\Services;

use App\Models\MasPaymentGateway;
use App\Models\MasPaymentTransaction;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MasNowPaymentsService
{
    public function gateway(): MasPaymentGateway
    {
        $gateway = MasPaymentGateway::query()->where('gateway_key', 'nowpayments')->first();
        if (!$gateway) {
            throw new RuntimeException('NOWPayments gateway record was not found.');
        }
        return $gateway;
    }

    public function settings(): array
    {
        $gateway = $this->gateway();
        $config = $gateway->configArray();
        $credentials = [];

        if (!empty($gateway->credentials)) {
            try {
                $decoded = json_decode(Crypt::decryptString((string) $gateway->credentials), true);
                $credentials = is_array($decoded) ? $decoded : [];
            } catch (\Throwable $exception) {
                Log::warning('NOWPayments credentials could not be decrypted.', ['message' => $exception->getMessage()]);
            }
        }

        return array_merge([
            'base_url' => 'https://api.nowpayments.io/v1',
            'price_currency' => 'usd',
            'pay_currency' => 'usdttrc20',
            'payout_currency' => 'usdttrc20',
            'fixed_rate' => true,
            'fee_paid_by_user' => false,
            'underpayment_tolerance' => 1,
            'expiry_minutes' => 60,
            'api_key' => '',
            'ipn_secret' => '',
        ], $config, $credentials);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->gateway()->enabled;
    }

    public function testConnection(): array
    {
        $settings = $this->settings();
        if (trim((string) $settings['api_key']) === '') {
            throw new RuntimeException('API Key is missing.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $settings['api_key'],
            'Accept' => 'application/json',
        ])->timeout(30)->get(rtrim((string) $settings['base_url'], '/') . '/status');

        if (!$response->successful()) {
            throw new RuntimeException('NOWPayments connection failed: HTTP ' . $response->status() . ' ' . $response->body());
        }

        return $response->json() ?: ['message' => $response->body()];
    }

    public function createPayment(MasPaymentTransaction $transaction): MasPaymentTransaction
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('NOWPayments is currently disabled.');
        }

        $settings = $this->settings();
        if (trim((string) $settings['api_key']) === '' || trim((string) $settings['ipn_secret']) === '') {
            throw new RuntimeException('NOWPayments API Key and IPN Secret are required.');
        }

        $callback = url('/api/webhooks/nowpayments');
        $body = [
            'price_amount' => (float) $transaction->price_amount,
            'price_currency' => strtolower((string) $transaction->price_currency),
            'pay_currency' => strtolower((string) $settings['pay_currency']),
            'payout_currency' => strtolower((string) $settings['payout_currency']),
            'order_id' => $transaction->reference,
            'order_description' => $transaction->description ?: $transaction->reference,
            'ipn_callback_url' => $callback,
            'is_fixed_rate' => !empty($settings['fixed_rate']),
            'is_fee_paid_by_user' => !empty($settings['fee_paid_by_user']),
        ];

        $response = Http::withHeaders([
            'x-api-key' => $settings['api_key'],
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(45)->post(rtrim((string) $settings['base_url'], '/') . '/payment', $body);

        $payload = $response->json();
        if (!$response->successful() || !is_array($payload) || empty($payload['payment_id'])) {
            $transaction->update([
                'status' => 'api_error',
                'raw_response' => $response->body(),
            ]);
            throw new RuntimeException('NOWPayments payment creation failed: ' . ($payload['message'] ?? $payload['error'] ?? $response->body()));
        }

        $transaction->update([
            'payment_id' => (string) $payload['payment_id'],
            'status' => (string) ($payload['payment_status'] ?? 'waiting'),
            'pay_address' => $payload['pay_address'] ?? null,
            'pay_amount' => isset($payload['pay_amount']) ? (float) $payload['pay_amount'] : null,
            'pay_currency' => $payload['pay_currency'] ?? $settings['pay_currency'],
            'raw_response' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'expires_at' => now()->addMinutes(max(10, (int) $settings['expiry_minutes'])),
        ]);

        return $transaction->fresh();
    }

    public function recheck(MasPaymentTransaction $transaction): MasPaymentTransaction
    {
        if (!$transaction->payment_id) {
            return $transaction;
        }

        $settings = $this->settings();
        $response = Http::withHeaders([
            'x-api-key' => $settings['api_key'],
            'Accept' => 'application/json',
        ])->timeout(30)->get(rtrim((string) $settings['base_url'], '/') . '/payment/' . rawurlencode((string) $transaction->payment_id));

        if (!$response->successful()) {
            throw new RuntimeException('NOWPayments status check failed: HTTP ' . $response->status());
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new RuntimeException('NOWPayments returned an invalid status response.');
        }

        return $this->applyPayload($payload);
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        if (!$signature) {
            return false;
        }

        $settings = $this->settings();
        $secret = trim((string) $settings['ipn_secret']);
        if ($secret === '') {
            return false;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return false;
        }

        $this->recursiveSort($payload);
        $normalized = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($normalized)) {
            return false;
        }

        $expected = hash_hmac('sha512', $normalized, $secret);
        return hash_equals(strtolower($expected), strtolower(trim($signature)));
    }

    public function applyPayload(array $payload): MasPaymentTransaction
    {
        $paymentId = isset($payload['payment_id']) ? (string) $payload['payment_id'] : '';
        $reference = isset($payload['order_id']) ? (string) $payload['order_id'] : '';

        $transaction = MasPaymentTransaction::query()
            ->when($paymentId !== '', fn ($query) => $query->where('payment_id', $paymentId))
            ->when($paymentId === '' && $reference !== '', fn ($query) => $query->where('reference', $reference))
            ->first();

        if (!$transaction) {
            throw new RuntimeException('Matching local payment transaction was not found.');
        }

        if ($reference !== '' && !hash_equals((string) $transaction->reference, $reference)) {
            throw new RuntimeException('NOWPayments order reference does not match.');
        }

        if (!empty($payload['pay_currency']) && $transaction->pay_currency && strtolower((string) $payload['pay_currency']) !== strtolower((string) $transaction->pay_currency)) {
            throw new RuntimeException('NOWPayments pay currency does not match.');
        }

        $status = (string) ($payload['payment_status'] ?? $transaction->status);
        $actuallyPaid = isset($payload['actually_paid']) ? (float) $payload['actually_paid'] : (isset($payload['pay_amount']) ? (float) $payload['pay_amount'] : null);

        $transaction->update([
            'status' => $status,
            'pay_address' => $payload['pay_address'] ?? $transaction->pay_address,
            'pay_amount' => isset($payload['pay_amount']) ? (float) $payload['pay_amount'] : $transaction->pay_amount,
            'pay_currency' => $payload['pay_currency'] ?? $transaction->pay_currency,
            'actually_paid' => $actuallyPaid,
            'raw_response' => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ]);

        if ($status === 'finished') {
            $this->complete($transaction->fresh());
        }

        return $transaction->fresh();
    }

    public function complete(MasPaymentTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $locked = MasPaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($locked->completed_at) {
                return;
            }

            if ((string) $locked->status !== 'finished') {
                throw new RuntimeException('Payment is not finished.');
            }

            $settings = $this->settings();
            $expected = (float) ($locked->pay_amount ?: 0);
            $actual = (float) ($locked->actually_paid ?: 0);
            $tolerance = max(0, min(20, (float) $settings['underpayment_tolerance']));
            $minimum = $expected > 0 ? $expected * (1 - ($tolerance / 100)) : 0;

            if ($expected > 0 && $actual + 0.00000001 < $minimum) {
                $locked->status = 'partially_paid';
                $locked->save();
                throw new RuntimeException('The received crypto amount is below the permitted tolerance.');
            }

            if ($locked->source_type === 'service_order_payment' && $locked->source_id && \Illuminate\Support\Facades\Schema::hasTable('service_order_payments')) {
                $payment = DB::table('service_order_payments')->where('id', $locked->source_id)->first();

                if ($payment) {
                    DB::table('service_order_payments')->where('id', $payment->id)->update([
                        'status' => 'verified',
                        'method' => 'nowpayments',
                        'verified_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if (\Illuminate\Support\Facades\Schema::hasTable('service_orders')) {
                        $order = DB::table('service_orders')->where('id', $payment->order_id)->first();
                        if ($order) {
                            $updates = ['updated_at' => now()];
                            $stage = (string) ($payment->payment_stage ?? 'final');

                            if ($stage === 'advance') {
                                $updates['paid_advance'] = $payment->amount;
                                $updates['payment_status'] = 'advance_paid';
                                $updates['order_status'] = 'in_progress';
                            } else {
                                $updates['paid_remaining'] = $payment->amount;
                                $updates['payment_status'] = 'fully_paid';
                            }

                            DB::table('service_orders')->where('id', $order->id)->update($updates);

                            if ((string) ($order->order_source ?? '') === 'plugin_purchase' && class_exists(\App\Services\MasPluginPurchaseService::class)) {
                                try {
                                    app(\App\Services\MasPluginPurchaseService::class)->provisionByServiceOrder((int) $order->id);
                                } catch (\Throwable $exception) {
                                    Log::error('Plugin provisioning after NOWPayments failed.', [
                                        'order_id' => $order->id,
                                        'message' => $exception->getMessage(),
                                    ]);
                                }
                            }
                        }
                    }
                }
            }

            $locked->completed_at = now();
            $locked->save();

            try {
                event('mas.payment.completed', [$locked]);
            } catch (\Throwable $exception) {
                Log::warning('Global payment completion hook failed.', ['message' => $exception->getMessage()]);
            }
        });
    }

    private function recursiveSort(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveSort($value);
            }
        }
    }
}
MAS_SERVICE;

    $adminController = <<<'MAS_ADMIN_CONTROLLER'
<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MasPaymentGateway;
use App\Models\MasPaymentTransaction;
use App\Services\MasNowPaymentsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class MasGlobalPaymentsController extends Controller
{
    public function gateways()
    {
        $gateway = MasPaymentGateway::query()->where('gateway_key', 'nowpayments')->firstOrFail();
        $config = $gateway->configArray();

        $hasApiKey = false;
        $hasIpnSecret = false;
        if ($gateway->credentials) {
            try {
                $credentials = json_decode(Crypt::decryptString((string) $gateway->credentials), true) ?: [];
                $hasApiKey = !empty($credentials['api_key']);
                $hasIpnSecret = !empty($credentials['ipn_secret']);
            } catch (\Throwable $ignored) {
            }
        }

        return view('mas_payments.admin.gateways', compact('gateway', 'config', 'hasApiKey', 'hasIpnSecret'));
    }

    public function save(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'nullable',
            'api_key' => 'nullable|string|max:500',
            'ipn_secret' => 'nullable|string|max:500',
            'base_url' => 'required|url|max:255',
            'price_currency' => 'required|string|max:20',
            'pay_currency' => 'required|string|max:40',
            'payout_currency' => 'required|string|max:40',
            'fixed_rate' => 'nullable',
            'fee_paid_by_user' => 'nullable',
            'underpayment_tolerance' => 'required|numeric|min:0|max:20',
            'expiry_minutes' => 'required|integer|min:10|max:1440',
        ]);

        $gateway = MasPaymentGateway::query()->where('gateway_key', 'nowpayments')->firstOrFail();
        $credentials = [];

        if ($gateway->credentials) {
            try {
                $credentials = json_decode(Crypt::decryptString((string) $gateway->credentials), true) ?: [];
            } catch (\Throwable $ignored) {
            }
        }

        if (trim((string) ($validated['api_key'] ?? '')) !== '') {
            $credentials['api_key'] = trim((string) $validated['api_key']);
        }
        if (trim((string) ($validated['ipn_secret'] ?? '')) !== '') {
            $credentials['ipn_secret'] = trim((string) $validated['ipn_secret']);
        }

        $config = [
            'base_url' => rtrim((string) $validated['base_url'], '/'),
            'price_currency' => strtolower((string) $validated['price_currency']),
            'pay_currency' => strtolower((string) $validated['pay_currency']),
            'payout_currency' => strtolower((string) $validated['payout_currency']),
            'fixed_rate' => $request->boolean('fixed_rate'),
            'fee_paid_by_user' => $request->boolean('fee_paid_by_user'),
            'underpayment_tolerance' => (float) $validated['underpayment_tolerance'],
            'expiry_minutes' => (int) $validated['expiry_minutes'],
        ];

        $gateway->update([
            'enabled' => $request->boolean('enabled'),
            'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
            'credentials' => Crypt::encryptString(json_encode($credentials, JSON_UNESCAPED_SLASHES)),
        ]);

        return back()->with('success', 'NOWPayments settings saved securely.');
    }

    public function test(MasNowPaymentsService $service)
    {
        try {
            $result = $service->testConnection();
            return back()->with('success', 'NOWPayments API connection is working: ' . json_encode($result));
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function transactions(Request $request)
    {
        $query = MasPaymentTransaction::query()->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($builder) use ($search): void {
                $builder->where('reference', 'like', '%' . $search . '%')
                    ->orWhere('payment_id', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        $transactions = $query->paginate(30)->withQueryString();
        return view('mas_payments.admin.transactions', compact('transactions'));
    }
}
MAS_ADMIN_CONTROLLER;

    $paymentController = <<<'MAS_PAYMENT_CONTROLLER'
<?php
namespace App\Http\Controllers;

use App\Models\MasPaymentTransaction;
use App\Services\MasNowPaymentsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MasNowPaymentsController extends Controller
{
    public function createForPluginOrder(int $order, MasNowPaymentsService $service)
    {
        if (!Schema::hasTable('service_orders') || !Schema::hasTable('service_order_payments')) {
            return back()->with('error', 'The Service Order payment tables are unavailable.');
        }

        $orderRow = DB::table('service_orders')->where('id', $order)->first();
        if (!$orderRow) {
            abort(404);
        }

        if (!empty($orderRow->user_id) && Auth::id() && (int) $orderRow->user_id !== (int) Auth::id()) {
            abort(403);
        }

        $payment = DB::table('service_order_payments')
            ->where('order_id', $order)
            ->whereNotIn('status', ['verified', 'paid'])
            ->orderByDesc('id')
            ->first();

        if (!$payment) {
            return back()->with('error', 'No pending payment request was found for this plugin order.');
        }

        return $this->createTransaction([
            'source_type' => 'service_order_payment',
            'source_id' => (int) $payment->id,
            'amount' => (float) $payment->amount,
            'currency' => strtolower((string) ($payment->currency ?: $orderRow->currency ?: 'usd')),
            'description' => trim((string) (($orderRow->order_no ?? 'Plugin order') . ' ' . ($payment->payment_stage ?? 'payment'))),
            'return_url' => url('/plugin-purchase/' . $order . '/payment'),
        ], $service);
    }

    public function createForServicePayment(int $payment, MasNowPaymentsService $service)
    {
        if (!Schema::hasTable('service_order_payments')) {
            return back()->with('error', 'The Service Order payment table is unavailable.');
        }

        $paymentRow = DB::table('service_order_payments')->where('id', $payment)->first();
        if (!$paymentRow) {
            abort(404);
        }

        $orderRow = Schema::hasTable('service_orders')
            ? DB::table('service_orders')->where('id', $paymentRow->order_id)->first()
            : null;

        if ($orderRow && !empty($orderRow->user_id) && Auth::id() && (int) $orderRow->user_id !== (int) Auth::id()) {
            abort(403);
        }

        return $this->createTransaction([
            'source_type' => 'service_order_payment',
            'source_id' => (int) $paymentRow->id,
            'amount' => (float) $paymentRow->amount,
            'currency' => strtolower((string) ($paymentRow->currency ?: ($orderRow->currency ?? 'usd'))),
            'description' => trim((string) (($orderRow->order_no ?? 'Service order') . ' ' . ($paymentRow->payment_stage ?? 'payment'))),
            'return_url' => $orderRow ? url('/client/orders/' . $orderRow->id) : url('/client/payments'),
        ], $service);
    }

    public function createGeneric(Request $request, MasNowPaymentsService $service)
    {
        $validated = $request->validate([
            'source_type' => 'nullable|string|max:100',
            'source_id' => 'nullable|integer|min:1',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|max:20',
            'description' => 'nullable|string|max:255',
            'return_url' => 'nullable|url|max:1000',
        ]);

        return $this->createTransaction($validated, $service);
    }

    public function show(MasPaymentTransaction $transaction, string $token)
    {
        $this->checkToken($transaction, $token);
        return view('mas_payments.show', compact('transaction'));
    }

    public function status(MasPaymentTransaction $transaction, string $token)
    {
        $this->checkToken($transaction, $token);

        return response()->json([
            'status' => $transaction->status,
            'payment_id' => $transaction->payment_id,
            'pay_amount' => $transaction->pay_amount,
            'pay_currency' => $transaction->pay_currency,
            'actually_paid' => $transaction->actually_paid,
            'completed' => !empty($transaction->completed_at),
            'redirect_url' => $transaction->completed_at ? ($transaction->return_url ?: url('/client/payments')) : null,
            'updated_at' => optional($transaction->updated_at)->toIso8601String(),
        ]);
    }

    public function recheck(MasPaymentTransaction $transaction, string $token, MasNowPaymentsService $service)
    {
        $this->checkToken($transaction, $token);

        try {
            $transaction = $service->recheck($transaction);
            return back()->with('success', 'Payment status refreshed: ' . $transaction->status);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function webhook(Request $request, MasNowPaymentsService $service)
    {
        $rawBody = $request->getContent();
        $signature = $request->header('x-nowpayments-sig');

        if (!$service->verifySignature($rawBody, $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return response()->json(['message' => 'Invalid JSON'], 422);
        }

        try {
            $transaction = $service->applyPayload($payload);
            return response()->json(['ok' => true, 'status' => $transaction->status]);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function createTransaction(array $data, MasNowPaymentsService $service)
    {
        if (!$service->isEnabled()) {
            return back()->with('error', 'NOWPayments is currently disabled.');
        }

        $settings = $service->settings();
        $currency = strtolower((string) ($data['currency'] ?? $settings['price_currency'] ?? 'usd'));

        $transaction = MasPaymentTransaction::create([
            'uuid' => (string) Str::uuid(),
            'public_token' => Str::random(64),
            'gateway' => 'nowpayments',
            'source_type' => $data['source_type'] ?? 'generic_purchase',
            'source_id' => $data['source_id'] ?? null,
            'user_id' => Auth::id(),
            'reference' => 'MAS-NP-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(8)),
            'description' => $data['description'] ?? 'MAKE ANYTHING SIMPLE payment',
            'price_amount' => (float) $data['amount'],
            'price_currency' => $currency,
            'status' => 'created',
            'return_url' => $data['return_url'] ?? url('/client/payments'),
        ]);

        try {
            $transaction = $service->createPayment($transaction);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('mas.nowpayments.show', [
            'transaction' => $transaction->id,
            'token' => $transaction->public_token,
        ]);
    }

    private function checkToken(MasPaymentTransaction $transaction, string $token): void
    {
        if (!hash_equals((string) $transaction->public_token, $token)) {
            abort(403);
        }
    }
}
MAS_PAYMENT_CONTROLLER;

    $gatewayView = <<<'MAS_GATEWAY_VIEW'
@extends('layouts.admin')

@section('content')
<style>
.mas-pay-wrap{max-width:1050px;margin:0 auto}.mas-pay-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:22px}.mas-pay-head h1{margin:0 0 7px}.mas-pay-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:20px}.mas-pay-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:24px;box-shadow:0 12px 35px rgba(15,23,42,.05)}.mas-pay-card h3{margin:0 0 18px}.mas-field{margin-bottom:15px}.mas-field label{display:block;font-weight:700;margin-bottom:7px;color:#253047}.mas-field input,.mas-field select{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:10px}.mas-two{display:grid;grid-template-columns:1fr 1fr;gap:13px}.mas-check{display:flex;gap:10px;align-items:center;margin:12px 0}.mas-check input{width:18px;height:18px}.mas-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}.mas-note{padding:14px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;color:#64748b;font-size:13px;line-height:1.65}.mas-status{display:flex;justify-content:space-between;padding:13px 0;border-bottom:1px solid #edf0f4}.mas-status:last-child{border:0}.mas-ok{color:#15803d}.mas-off{color:#b45309}@media(max-width:850px){.mas-pay-grid,.mas-two{grid-template-columns:1fr}.mas-pay-head{display:block}}
</style>
<div class="container-fluid">
<div class="mas-pay-wrap">
    <div class="mas-pay-head">
        <div><h1>Global Payments</h1><p class="text-muted mb-0">Manage NOWPayments once and use it across Plugin, Extension and Service Order checkouts.</p></div>
        <a class="btn btn-outline-secondary" href="{{ url('/admin/payments/transactions') }}">Transactions</a>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <div class="mas-pay-grid">
        <form method="post" action="{{ url('/admin/payments/gateways/nowpayments') }}" class="mas-pay-card">
            @csrf
            <h3>NOWPayments settings</h3>

            <label class="mas-check"><input type="checkbox" name="enabled" value="1" {{ $gateway->enabled ? 'checked' : '' }}> <span>Enable NOWPayments globally</span></label>

            <div class="mas-field"><label>API Key</label><input type="password" name="api_key" autocomplete="new-password" placeholder="{{ $hasApiKey ? 'Saved securely — leave blank to keep' : 'Paste API key' }}"></div>
            <div class="mas-field"><label>IPN Secret</label><input type="password" name="ipn_secret" autocomplete="new-password" placeholder="{{ $hasIpnSecret ? 'Saved securely — leave blank to keep' : 'Paste IPN secret' }}"></div>
            <div class="mas-field"><label>API Base URL</label><input name="base_url" value="{{ old('base_url', $config['base_url'] ?? 'https://api.nowpayments.io/v1') }}" required></div>

            <div class="mas-two">
                <div class="mas-field"><label>Price currency</label><input name="price_currency" value="{{ old('price_currency', $config['price_currency'] ?? 'usd') }}" required></div>
                <div class="mas-field"><label>Pay currency</label><input name="pay_currency" value="{{ old('pay_currency', $config['pay_currency'] ?? 'usdttrc20') }}" required></div>
                <div class="mas-field"><label>Payout currency</label><input name="payout_currency" value="{{ old('payout_currency', $config['payout_currency'] ?? 'usdttrc20') }}" required></div>
                <div class="mas-field"><label>Expiry minutes</label><input type="number" min="10" max="1440" name="expiry_minutes" value="{{ old('expiry_minutes', $config['expiry_minutes'] ?? 60) }}" required></div>
                <div class="mas-field"><label>Underpayment tolerance %</label><input type="number" step="0.01" min="0" max="20" name="underpayment_tolerance" value="{{ old('underpayment_tolerance', $config['underpayment_tolerance'] ?? 1) }}" required></div>
            </div>

            <label class="mas-check"><input type="checkbox" name="fixed_rate" value="1" {{ !empty($config['fixed_rate']) ? 'checked' : '' }}> <span>Use fixed exchange rate</span></label>
            <label class="mas-check"><input type="checkbox" name="fee_paid_by_user" value="1" {{ !empty($config['fee_paid_by_user']) ? 'checked' : '' }}> <span>Customer pays blockchain/service fee</span></label>

            <div class="mas-actions"><button class="btn btn-primary" type="submit">Save Gateway</button></div>
        </form>

        <div class="mas-pay-card">
            <h3>Connection & webhook</h3>
            <div class="mas-status"><span>Gateway</span><b class="{{ $gateway->enabled ? 'mas-ok' : 'mas-off' }}">{{ $gateway->enabled ? 'Enabled' : 'Disabled' }}</b></div>
            <div class="mas-status"><span>API Key</span><b>{{ $hasApiKey ? 'Saved' : 'Missing' }}</b></div>
            <div class="mas-status"><span>IPN Secret</span><b>{{ $hasIpnSecret ? 'Saved' : 'Missing' }}</b></div>
            <div class="mas-status"><span>Network</span><b>{{ strtoupper($config['pay_currency'] ?? 'usdttrc20') }}</b></div>

            <form method="post" action="{{ url('/admin/payments/gateways/nowpayments/test') }}" class="mas-actions">@csrf<button class="btn btn-outline-primary" type="submit">Test API Connection</button></form>

            <div class="mas-note mt-3"><b>IPN callback URL</b><br><code>{{ url('/api/webhooks/nowpayments') }}</code><br><br>Add this URL in NOWPayments Instant Payment Notifications. Credentials are encrypted with the Laravel application key.</div>
        </div>
    </div>
</div>
</div>
@endsection
MAS_GATEWAY_VIEW;

    $transactionsView = <<<'MAS_TRANSACTIONS_VIEW'
@extends('layouts.admin')

@section('content')
<style>
.mas-tx-wrap{max-width:1200px;margin:0 auto}.mas-tx-head{display:flex;justify-content:space-between;gap:18px;margin-bottom:20px}.mas-tx-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden}.mas-tx-filter{display:flex;gap:10px;padding:16px;border-bottom:1px solid #edf0f4}.mas-tx-filter input,.mas-tx-filter select{padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px}.mas-tx-table{width:100%;border-collapse:collapse}.mas-tx-table th,.mas-tx-table td{padding:13px 14px;border-bottom:1px solid #edf0f4;text-align:left;vertical-align:top}.mas-tx-table th{font-size:12px;text-transform:uppercase;color:#64748b;background:#fafbfc}.mas-pill{display:inline-flex;padding:5px 9px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:800}.mas-muted{color:#64748b;font-size:12px}@media(max-width:900px){.mas-tx-card{overflow:auto}.mas-tx-head{display:block}.mas-tx-filter{min-width:760px}.mas-tx-table{min-width:980px}}
</style>
<div class="container-fluid"><div class="mas-tx-wrap">
<div class="mas-tx-head"><div><h1>Payment Transactions</h1><p class="text-muted mb-0">NOWPayments activity across the marketplace.</p></div><a class="btn btn-outline-secondary" href="{{ url('/admin/payments/gateways') }}">Gateways</a></div>
<div class="mas-tx-card">
<form class="mas-tx-filter" method="get"><input name="search" value="{{ request('search') }}" placeholder="Reference or payment ID"><select name="status"><option value="">All statuses</option>@foreach(['created','waiting','confirming','confirmed','sending','finished','partially_paid','expired','failed','refunded'] as $status)<option value="{{ $status }}" {{ request('status')===$status?'selected':'' }}>{{ ucfirst(str_replace('_',' ',$status)) }}</option>@endforeach</select><button class="btn btn-primary">Filter</button></form>
<table class="mas-tx-table"><thead><tr><th>Reference</th><th>Source</th><th>Amount</th><th>Crypto</th><th>Status</th><th>Updated</th></tr></thead><tbody>
@forelse($transactions as $tx)
<tr><td><b>{{ $tx->reference }}</b><div class="mas-muted">{{ $tx->payment_id ?: 'Payment ID pending' }}</div></td><td>{{ $tx->source_type ?: 'generic' }}<div class="mas-muted">#{{ $tx->source_id ?: '—' }}</div></td><td>{{ strtoupper($tx->price_currency) }} {{ number_format((float)$tx->price_amount,2) }}</td><td>{{ $tx->pay_amount ? rtrim(rtrim(number_format((float)$tx->pay_amount,8,'.',''),'0'),'.') : '—' }} {{ strtoupper($tx->pay_currency ?: '') }}<div class="mas-muted">Paid: {{ $tx->actually_paid ?: '0' }}</div></td><td><span class="mas-pill">{{ $tx->status }}</span></td><td>{{ optional($tx->updated_at)->format('d M Y H:i') }}</td></tr>
@empty<tr><td colspan="6" class="text-center text-muted py-5">No transactions found.</td></tr>@endforelse
</tbody></table>
</div><div class="mt-3">{{ $transactions->links() }}</div>
</div></div>
@endsection
MAS_TRANSACTIONS_VIEW;

    $paymentView = <<<'MAS_PAYMENT_VIEW'
@extends('layouts.front')

@section('title', 'Crypto Payment | MAKE ANYTHING SIMPLE')
@section('meta', 'Complete your secure cryptocurrency payment.')

@section('content')
<style>
.mas-np-page{background:#050505;color:#fff;min-height:80vh;padding:140px 18px 90px}.mas-np-shell{width:min(980px,100%);margin:0 auto}.mas-np-grid{display:grid;grid-template-columns:1fr .85fr;gap:22px}.mas-np-card{border:1px solid rgba(255,255,255,.15);border-radius:24px;background:linear-gradient(145deg,rgba(255,255,255,.055),rgba(255,255,255,.018));padding:28px}.mas-np-title{font-size:clamp(38px,5vw,62px);line-height:1;margin:0 0 14px;letter-spacing:-.05em}.mas-np-muted{color:rgba(255,255,255,.64);line-height:1.7}.mas-np-amount{font-size:34px;font-weight:900;margin:24px 0 8px}.mas-np-network{display:inline-flex;padding:7px 11px;border-radius:999px;background:rgba(124,167,255,.13);color:#c6d7ff;font-size:12px;font-weight:800}.mas-np-qr{display:grid;place-items:center;min-height:270px;background:#fff;border-radius:18px;padding:18px;margin-bottom:18px}.mas-np-address{word-break:break-all;padding:15px;border:1px solid rgba(255,255,255,.14);border-radius:13px;background:rgba(255,255,255,.04);font-family:monospace}.mas-np-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}.mas-np-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 17px;border-radius:999px;border:1px solid rgba(255,255,255,.3);background:transparent;color:#fff!important;text-decoration:none!important;font-weight:800;cursor:pointer}.mas-np-btn.primary{background:#fff;color:#000!important;border-color:#fff}.mas-np-status{margin-top:20px;padding:16px;border-radius:14px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.13)}.mas-np-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#f3b23c;margin-right:8px;box-shadow:0 0 18px rgba(243,178,60,.7)}.mas-np-warning{margin-top:18px;color:#ffcf70;font-size:13px;line-height:1.6}@media(max-width:800px){.mas-np-grid{grid-template-columns:1fr}.mas-np-page{padding-top:115px}.mas-np-card{padding:21px}.mas-np-btn{width:100%}}
</style>
<main class="mas-np-page"><div class="mas-np-shell">
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
<div class="mas-np-grid">
<section class="mas-np-card"><span class="mas-np-network">{{ strtoupper($transaction->pay_currency ?: 'USDTTRC20') }}</span><h1 class="mas-np-title mt-3">Pay with crypto</h1><p class="mas-np-muted">Send the exact amount to the address shown. The page updates automatically after blockchain confirmation.</p><div class="mas-np-amount" id="masNpAmount">{{ rtrim(rtrim(number_format((float)$transaction->pay_amount,8,'.',''),'0'),'.') }} {{ strtoupper($transaction->pay_currency) }}</div><div class="mas-np-muted">Order: {{ $transaction->reference }}</div><div class="mas-np-status"><span class="mas-np-dot"></span><b id="masNpStatus">{{ ucfirst(str_replace('_',' ',$transaction->status)) }}</b><div class="mas-np-muted mt-1" id="masNpStatusText">Waiting for the payment network.</div></div><div class="mas-np-warning">Send only {{ strtoupper($transaction->pay_currency) }} using the correct network. Sending another token or network may permanently lose the funds.</div><div class="mas-np-actions"><button type="button" class="mas-np-btn primary" onclick="masCopy('{{ addslashes($transaction->pay_address) }}')">Copy address</button><button type="button" class="mas-np-btn" onclick="masCopy('{{ $transaction->pay_amount }}')">Copy amount</button><form method="post" action="{{ route('mas.nowpayments.recheck',[$transaction->id,$transaction->public_token]) }}">@csrf<button class="mas-np-btn" type="submit">Refresh status</button></form></div></section>
<aside class="mas-np-card"><div class="mas-np-qr" id="masNpQr"></div><div class="mas-np-address" id="masNpAddress">{{ $transaction->pay_address }}</div><p class="mas-np-muted mt-3 mb-0">Payment ID: {{ $transaction->payment_id }}</p></aside>
</div></div></main>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function(){var address=@json($transaction->pay_address);if(window.QRCode&&address){new QRCode(document.getElementById('masNpQr'),{text:address,width:230,height:230,correctLevel:QRCode.CorrectLevel.M});}var statusUrl=@json(route('mas.nowpayments.status',[$transaction->id,$transaction->public_token]));var labels={waiting:'Waiting for payment',confirming:'Payment detected — confirming',confirmed:'Blockchain confirmed',sending:'Processing settlement',finished:'Payment completed',partially_paid:'Partially paid',expired:'Payment expired',failed:'Payment failed',refunded:'Payment refunded'};var timer=setInterval(function(){fetch(statusUrl,{headers:{'Accept':'application/json'}}).then(function(r){return r.json();}).then(function(data){document.getElementById('masNpStatus').textContent=labels[data.status]||data.status;document.getElementById('masNpStatusText').textContent=data.completed?'Your purchase is now active.':'Last checked '+new Date().toLocaleTimeString();if(data.completed){clearInterval(timer);setTimeout(function(){window.location.href=data.redirect_url||'/client/payments';},1800);}}).catch(function(){});},5000);})();function masCopy(value){navigator.clipboard.writeText(value).then(function(){alert('Copied');});}
</script>
@endsection
MAS_PAYMENT_VIEW;

    return [
        'app/Models/MasPaymentGateway.php' => $gatewayModel,
        'app/Models/MasPaymentTransaction.php' => $transactionModel,
        'app/Services/MasNowPaymentsService.php' => $service,
        'app/Http/Controllers/Admin/MasGlobalPaymentsController.php' => $adminController,
        'app/Http/Controllers/MasNowPaymentsController.php' => $paymentController,
        'resources/views/mas_payments/admin/gateways.blade.php' => $gatewayView,
        'resources/views/mas_payments/admin/transactions.blade.php' => $transactionsView,
        'resources/views/mas_payments/show.blade.php' => $paymentView,
    ];
}

function masnp_web_routes(): string
{
    return <<<'MAS_WEB_ROUTES'
// MAS_GLOBAL_NOWPAYMENTS_WEB_ROUTES_START
\Illuminate\Support\Facades\Route::middleware(['admin'])->prefix('admin/payments')->group(function () {
    \Illuminate\Support\Facades\Route::get('/gateways', [\App\Http\Controllers\Admin\MasGlobalPaymentsController::class, 'gateways'])->name('mas.payments.gateways');
    \Illuminate\Support\Facades\Route::post('/gateways/nowpayments', [\App\Http\Controllers\Admin\MasGlobalPaymentsController::class, 'save'])->name('mas.payments.nowpayments.save');
    \Illuminate\Support\Facades\Route::post('/gateways/nowpayments/test', [\App\Http\Controllers\Admin\MasGlobalPaymentsController::class, 'test'])->name('mas.payments.nowpayments.test');
    \Illuminate\Support\Facades\Route::get('/transactions', [\App\Http\Controllers\Admin\MasGlobalPaymentsController::class, 'transactions'])->name('mas.payments.transactions');
});

\Illuminate\Support\Facades\Route::middleware(['auth'])->group(function () {
    \Illuminate\Support\Facades\Route::post('/plugin-purchase/{order}/crypto', [\App\Http\Controllers\MasNowPaymentsController::class, 'createForPluginOrder'])->name('mas.nowpayments.plugin.create');
    \Illuminate\Support\Facades\Route::post('/payments/nowpayments/service-order/{payment}/create', [\App\Http\Controllers\MasNowPaymentsController::class, 'createForServicePayment'])->name('mas.nowpayments.service.create');
    \Illuminate\Support\Facades\Route::post('/payments/nowpayments/create', [\App\Http\Controllers\MasNowPaymentsController::class, 'createGeneric'])->name('mas.nowpayments.generic.create');
});

\Illuminate\Support\Facades\Route::get('/pay/nowpayments/{transaction}/{token}', [\App\Http\Controllers\MasNowPaymentsController::class, 'show'])->name('mas.nowpayments.show');
\Illuminate\Support\Facades\Route::get('/pay/nowpayments/{transaction}/{token}/status', [\App\Http\Controllers\MasNowPaymentsController::class, 'status'])->name('mas.nowpayments.status');
\Illuminate\Support\Facades\Route::post('/pay/nowpayments/{transaction}/{token}/recheck', [\App\Http\Controllers\MasNowPaymentsController::class, 'recheck'])->name('mas.nowpayments.recheck');
// MAS_GLOBAL_NOWPAYMENTS_WEB_ROUTES_END
MAS_WEB_ROUTES;
}

function masnp_api_routes(): string
{
    return <<<'MAS_API_ROUTES'
// MAS_GLOBAL_NOWPAYMENTS_API_ROUTES_START
\Illuminate\Support\Facades\Route::post('/webhooks/nowpayments', [\App\Http\Controllers\MasNowPaymentsController::class, 'webhook'])->name('mas.nowpayments.webhook');
// MAS_GLOBAL_NOWPAYMENTS_API_ROUTES_END
MAS_API_ROUTES;
}

function masnp_menu_block(): string
{
    return <<<'MAS_MENU'
{{-- MAS_GLOBAL_NOWPAYMENTS_MENU_START --}}
@if(Auth::check() && optional(Auth::user()->role)->name == 'administrator')
<li class="nav-item {{ request()->is('admin/payments*') ? 'active' : '' }}">
    <a class="nav-link" href="{{ url('/admin/payments/gateways') }}">
        <i class="fas fa-fw fa-wallet"></i>
        <span>Payments</span>
    </a>
</li>
@endif
{{-- MAS_GLOBAL_NOWPAYMENTS_MENU_END --}}
MAS_MENU;
}

function masnp_plugin_button(): string
{
    return <<<'MAS_PLUGIN_BUTTON'
{{-- MAS_GLOBAL_NOWPAYMENTS_PLUGIN_BUTTON_START --}}
@if(isset($order) && auth()->check())
<div style="margin-top:18px;padding:20px;border:1px solid rgba(255,255,255,.16);border-radius:18px;background:rgba(255,255,255,.035)">
    <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap">
        <div>
            <strong style="display:block;color:#fff;font-size:17px">Pay with Crypto</strong>
            <span style="color:rgba(255,255,255,.62);font-size:13px">USDT TRC20 address, QR code and automatic verification.</span>
        </div>
        <form method="post" action="{{ url('/plugin-purchase/'.$order->id.'/crypto') }}">
            @csrf
            <button type="submit" style="border:0;border-radius:999px;padding:12px 18px;background:#fff;color:#000;font-weight:800;cursor:pointer">Pay with USDT</button>
        </form>
    </div>
</div>
@endif
{{-- MAS_GLOBAL_NOWPAYMENTS_PLUGIN_BUTTON_END --}}
MAS_PLUGIN_BUTTON;
}

function masnp_install(string $root): array
{
    $payload = masnp_payload();
    $targets = array_keys($payload);
    $targets[] = 'routes/web.php';
    $targets[] = 'routes/api.php';
    $targets[] = 'resources/views/layouts/admin.blade.php';

    $pluginViewCandidates = [
        'resources/views/mas_plugins/payment.blade.php',
        'resources/views/mas_plugins/checkout/payment.blade.php',
    ];

    foreach ($pluginViewCandidates as $candidate) {
        if (is_file($root . '/' . $candidate)) {
            $targets[] = $candidate;
        }
    }

    $targets = array_values(array_unique($targets));
    $backupDirectory = $root . '/storage/app/mas_installer_backups/global_nowpayments_' . date('Ymd_His');
    $manifest = [];

    foreach ($targets as $relative) {
        $source = $root . '/' . $relative;
        $manifest[$relative] = ['existed' => is_file($source)];

        if (is_file($source)) {
            $backup = $backupDirectory . '/' . $relative;
            if (!is_dir(dirname($backup)) && !@mkdir(dirname($backup), 0775, true) && !is_dir(dirname($backup))) {
                throw new RuntimeException('Could not create backup path for ' . $relative);
            }
            if (!@copy($source, $backup)) {
                throw new RuntimeException('Backup failed for ' . $relative);
            }
        }
    }

    masnp_write_json($backupDirectory . '/manifest.json', $manifest);

    foreach ($payload as $relative => $contents) {
        masnp_write_atomic($root . '/' . $relative, $contents);
    }

    $webPath = $root . '/routes/web.php';
    $apiPath = $root . '/routes/api.php';
    $menuPath = $root . '/resources/views/layouts/admin.blade.php';

    if (!is_file($webPath) || !is_file($apiPath) || !is_file($menuPath)) {
        throw new RuntimeException('Required route or admin layout file is missing.');
    }

    $web = (string) file_get_contents($webPath);
    $web = masnp_append_route_block(
        $web,
        '// MAS_GLOBAL_NOWPAYMENTS_WEB_ROUTES_START',
        '// MAS_GLOBAL_NOWPAYMENTS_WEB_ROUTES_END',
        masnp_web_routes()
    );
    masnp_write_atomic($webPath, $web);

    $api = (string) file_get_contents($apiPath);
    $api = masnp_append_route_block(
        $api,
        '// MAS_GLOBAL_NOWPAYMENTS_API_ROUTES_START',
        '// MAS_GLOBAL_NOWPAYMENTS_API_ROUTES_END',
        masnp_api_routes()
    );
    masnp_write_atomic($apiPath, $api);

    $menu = (string) file_get_contents($menuPath);
    $menu = masnp_patch_menu($menu, masnp_menu_block());
    masnp_write_atomic($menuPath, $menu);

    $patchedPluginView = null;
    foreach ($pluginViewCandidates as $candidate) {
        $path = $root . '/' . $candidate;
        if (is_file($path)) {
            $contents = (string) file_get_contents($path);
            $contents = masnp_patch_plugin_payment_view($contents, masnp_plugin_button());
            masnp_write_atomic($path, $contents);
            $patchedPluginView = $candidate;
            break;
        }
    }

    masnp_create_tables($root);
    $cacheRemoved = masnp_clear_cache($root);

    $state = [
        'version' => MAS_NP_INSTALLER_VERSION,
        'installed_at' => date(DATE_ATOM),
        'backup_directory' => $backupDirectory,
        'manifest' => $manifest,
        'generated_files' => array_keys($payload),
        'plugin_payment_view' => $patchedPluginView,
        'cache_removed' => $cacheRemoved,
    ];

    masnp_write_json(masnp_state_path($root), $state);

    return [
        'message' => 'Global NOWPayments gateway installed successfully.',
        'backup_directory' => $backupDirectory,
        'plugin_payment_view' => $patchedPluginView,
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
        $existed = !empty($meta['existed']);

        if ($existed) {
            $backup = $backupDirectory . '/' . $relative;
            if (!is_file($backup)) {
                throw new RuntimeException('Backup file is missing: ' . $relative);
            }
            masnp_write_atomic($destination, (string) file_get_contents($backup));
        } elseif (is_file($destination)) {
            if (!@unlink($destination)) {
                throw new RuntimeException('Could not remove generated file: ' . $relative);
            }
        }
    }

    try {
        masnp_boot($root);
        if (\Illuminate\Support\Facades\Schema::hasTable('mas_payment_gateways')) {
            \Illuminate\Support\Facades\DB::table('mas_payment_gateways')
                ->where('gateway_key', 'nowpayments')
                ->update(['enabled' => 0, 'updated_at' => now()]);
        }
    } catch (\Throwable $ignored) {
    }

    masnp_clear_cache($root);
    @unlink(masnp_state_path($root));

    return [
        'message' => 'Previous files were restored and NOWPayments was disabled. Transaction tables were preserved for audit history.',
    ];
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
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo masnp_h(MAS_NP_INSTALLER_NAME); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#07090d;color:#eaf0f7;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{width:min(920px,calc(100% - 28px));margin:42px auto}.card{background:#11151c;border:1px solid #29313d;border-radius:22px;padding:28px;box-shadow:0 22px 80px rgba(0,0,0,.35)}.tag{display:inline-flex;padding:7px 11px;border-radius:999px;background:#1c2635;color:#9dc1ff;font-size:12px;font-weight:800;letter-spacing:.08em}h1{margin:16px 0 8px;font-size:34px}p{color:#aab5c3;line-height:1.68}.status{margin:22px 0;padding:16px;border-radius:14px;background:#0b0e13;border:1px solid #27303b}.ok{border-color:#245e42;color:#aef2cd}.bad{border-color:#7b3030;color:#ffc4c4}.buttons{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}button,a.btn{appearance:none;border:1px solid #3b4656;background:#1a2130;color:#fff;border-radius:12px;padding:12px 16px;text-decoration:none;font-weight:800;cursor:pointer}button.primary,a.primary{background:#fff;color:#050505;border-color:#fff}code{word-break:break-word;color:#c8d8ee}ul{color:#b7c1ce;line-height:1.9}small{color:#7f8a99}
</style>
</head>
<body><div class="wrap"><div class="card">
<span class="tag">VERSION <?php echo masnp_h(MAS_NP_INSTALLER_VERSION); ?></span>
<h1><?php echo masnp_h(MAS_NP_INSTALLER_NAME); ?></h1>
<p>Installs NOWPayments as a global encrypted gateway with QR/address checkout, signed IPN verification, transaction history, plugin-purchase provisioning, automatic backup and rollback.</p>

<?php if ($error !== ''): ?>
<div class="status bad"><strong>Installation error</strong><br><?php echo masnp_h($error); ?></div>
<?php elseif (is_array($result)): ?>
<div class="status ok"><strong><?php echo masnp_h($result['message'] ?? 'Action completed.'); ?></strong></div>
<?php endif; ?>

<div class="status"><strong>Status:</strong> <?php echo $installed ? 'Installed' : 'Not installed'; ?><br><strong>Laravel root:</strong> <code><?php echo masnp_h($root); ?></code><br><strong>Webhook:</strong> <code><?php echo masnp_h('https://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com') . '/api/webhooks/nowpayments'); ?></code></div>

<?php if ($installed): ?>
<ul><li>Global Payments admin menu installed</li><li>API Key and IPN Secret encrypted by Laravel</li><li>QR/address payment page and 5-second status polling installed</li><li>Signed HMAC SHA-512 webhook installed</li><li>Service Order and Plugin Purchase auto-completion installed</li><li>Generic completion hook: <code>mas.payment.completed</code></li><li>Existing Stripe and manual gateways remain unchanged</li></ul>
<?php endif; ?>

<div class="buttons">
<form method="post"><input type="hidden" name="action" value="repair"><button class="primary" type="submit">Repair / install again</button></form>
<?php if ($installed): ?><form method="post" onsubmit="return confirm('Restore previous files and disable NOWPayments?');"><input type="hidden" name="action" value="rollback"><button type="submit">Rollback latest update</button></form><?php endif; ?>
<a class="btn" href="/admin/payments/gateways" target="_blank" rel="noopener">Open Gateways</a><a class="btn" href="/admin/payments/transactions" target="_blank" rel="noopener">Open Transactions</a>
</div>
<p><small>After testing, delete this installer from the public directory because it has no access token. Rollback preserves payment transaction tables for financial audit history.</small></p>
</div></div></body></html>
