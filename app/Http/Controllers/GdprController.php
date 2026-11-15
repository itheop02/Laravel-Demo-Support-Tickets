<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Throwable;

require_once base_path('integration/privacy-lib/GdprRouter.php');
final class GdprController extends Controller
{
    private function json(mixed $value)
    {
        return response()->json($value)->header('Cache-Control', 'no-store');
    }

    private function badInput(string $message): never
    {
        throw ValidationException::withMessages(['request' => $message]);
    }

    public function page()
    {
        return response()->view('gdpr')->header('Cache-Control', 'no-store');
    }

    public function csrf()
    {
        return $this->json(['token' => csrf_token()]);
    }

    private function read(Request $request, string $policy, bool $write = false): array
    {
        abort_unless($request->isJson(), 415, 'JSON content type required');
        $raw = $request->getContent();
        abort_if(strlen($raw) > 16384, 413, 'Request too large');
        try {
            $body = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->badInput('Invalid JSON');
        }
        if (! $body instanceof stdClass) {
            $this->badInput('JSON object required');
        }

        return $this->validateRequest($policy, $body, $write);
    }

    public function consent(Request $request)
    {
        $value = $this->read($request, 'consent', true);
        DB::table('gdpr_consents')->updateOrInsert(['user_id' => $request->user()->getKey()], $value);

        return $this->json($value);
    }

    public function evaluate(Request $request, string $policy)
    {
        $value = $this->read($request, $policy);
        if ($policy === 'breach') {
            abort_unless($request->user()->hasRole('admin'), 403, 'Administrator access required');
        }
        $user = $request->user();
        $id = (string) $user->getKey();
        $config = config('privacy.metadata');
        $consent = DB::table('gdpr_consents')->where('user_id', $user->getKey())->first();
        $tickets = config('laravel_ticket.table_names.tickets', 'tickets');
        $messages = config('laravel_ticket.table_names.messages.table', 'messages');
        // Scope database queries by authenticated ownership even for administrator sessions.
        $owned = DB::table($tickets)->where('user_id', $user->getKey())->orderBy('id')
            ->get(['id', 'title', 'message', 'priority', 'status', 'created_at', 'updated_at'])->map(fn ($x) => (array) $x)->all();
        $authored = DB::table($messages.' as m')->join($tickets.' as t', 'm.ticket_id', '=', 't.id')
            ->where('m.user_id', $user->getKey())->where('t.user_id', $user->getKey())->orderBy('m.id')
            ->get(['m.id', 'm.ticket_id', 'm.message', 'm.created_at', 'm.updated_at'])->map(fn ($x) => (array) $x)->all();
        $values = ['name' => $user->name, 'email' => $user->email, 'tickets' => $owned, 'messages' => $authored];
        $input = array_intersect_key($config, array_flip(['controller', 'dpo', 'storage', 'lawful_purposes', 'third_parties', 'services', 'exceptions', 'supervisory_authorities']));
        $input['consents'] = $consent ? (object) [$id => ['value' => $consent->value, 'withdrawn' => (bool) $consent->withdrawn]] : new stdClass();
        $input['users'] = [['id' => $id, 'data_items' => array_map(fn ($field) => ['field' => $field, 'value' => $values[$field], 'purpose' => 'contract', 'source' => 'user', 'purpose_presented' => true], array_keys($values))]];
        $input['request'] = new stdClass();
        if ($policy === 'access') {
            $input['request']->access = ['user_id' => $id];
        }
        if (in_array($policy, ['rectification', 'erasure', 'restriction', 'portability', 'objection', 'complaint'], true)) {
            $input['request']->$policy = array_merge($config['request_defaults'][$policy] ?? [], $policy === 'portability' ? ['transfer_requested' => false, 'transfer_service_id' => ''] : [], $value, ['request_id' => $value['request_id'] ?? bin2hex(random_bytes(16)), 'user_id' => $id, 'target_user_id' => $id]);
        }
        if ($policy === 'breach') {
            $input['breach'] = array_merge($config['breach_defaults'], $value, ['affected_users' => [$id]]);
        }
        try {
            $result = (new \GdprRouter(config('privacy.opa_query_url')))->route($policy, json_decode(json_encode($input, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR), $id);
        } catch (Throwable) {
            return $this->json(['error' => 'OPA evaluation failed'])->setStatusCode(502);
        }

        return $this->json($result);
    }

private function validateRequest(string $policy, stdClass $body, bool $writeConsent): array
{
    $schemas = [
        'consent' => [], 'lawful' => [], 'information' => [], 'access' => [],
        'rectification' => ['request_id', 'updates'],
        'erasure' => ['request_id', 'reason', 'fields'],
        'restriction' => ['request_id', 'reason', 'fields'],
        'portability' => ['request_id', 'transfer_requested', 'transfer_service_id'],
        'objection' => ['request_id', 'reason', 'fields'],
        'complaint' => ['request_id', 'authority_id', 'description'],
        'breach' => ['incident_id', 'detected', 'hours_since_detected', 'risks', 'summary'],
    ];
    if (! array_key_exists($policy, $schemas)) {
        $this->badInput('Unknown GDPR operation');
    }
    $allowed = $writeConsent ? ['value', 'withdrawn'] : $schemas[$policy];
    $value = get_object_vars($body);
    foreach ($value as $key => $item) {
        if (! in_array($key, $allowed, true)) {
            $this->badInput('Unknown request field: '.$key);
        }
        if (in_array($key, ['withdrawn', 'detected', 'transfer_requested'], true)) {
            if (! is_bool($item)) {
                $this->badInput($key.' must be boolean');
            }
        } elseif ($key === 'hours_since_detected') {
            if ((! is_int($item) && ! is_float($item)) || $item < 0) {
                $this->badInput('Invalid incident time');
            }
        } elseif ($key === 'fields' || $key === 'risks') {
            $choices = $key === 'fields' ? ['name', 'email', 'tickets', 'messages'] : ['rights_freedoms', 'identity_theft', 'discrimination', 'financial_loss', 'reputational_damage', 'minor'];
            if (! is_array($item) || ! $item || count($item) > count($choices)) {
                $this->badInput('Invalid '.$key);
            }
            foreach ($item as $field) {
                if (! is_string($field) || ! in_array($field, $choices, true)) {
                    $this->badInput('Invalid '.$key);
                }
            }
            if (count(array_unique($item)) !== count($item)) {
                $this->badInput('Duplicate '.$key);
            }
        } elseif ($key === 'updates') {
            if (! $item instanceof stdClass || ! get_object_vars($item)) {
                $this->badInput('Updates must be a nonempty object');
            }
            foreach (get_object_vars($item) as $field => $updated) {
                if (! in_array($field, ['name', 'email'], true) || ! is_string($updated) || $updated === '' || strlen($updated) > 200) {
                    $this->badInput('Invalid update');
                }
                if ($field === 'email' && ! filter_var($updated, FILTER_VALIDATE_EMAIL)) {
                    $this->badInput('Invalid email');
                }
            }
        } else {
            $limit = $key === 'description' ? 2000 : ($key === 'summary' ? 2000 : 128);
            if (! is_string($item) || $item === '' || strlen($item) > $limit) {
                $this->badInput('Invalid '.$key);
            }
        }
    }
    foreach ($writeConsent ? ['value', 'withdrawn'] : match ($policy) {
        'rectification' => ['updates'], 'complaint' => ['authority_id', 'description'], 'breach' => ['incident_id'], default => [],
    } as $required) {
        if (! array_key_exists($required, $value)) {
            $this->badInput('Missing '.$required);
        }
    }
    if ($writeConsent && ! in_array($value['value'], ['yes', 'no'], true)) {
        $this->badInput('Invalid consent value');
    }

    return $value;
}
}
