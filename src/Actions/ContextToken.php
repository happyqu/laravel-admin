<?php

namespace Encore\Admin\Actions;

use Illuminate\Http\Exceptions\HttpResponseException;

/** Small, readable JSON parameters authenticated for the current login. */
class ContextToken
{
    public const MAX_PAYLOAD_BYTES = 65536;
    private const MAX_TOKEN_BYTES = 90000;

    public static function encode(array $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR, 64);
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            throw new \LengthException('Action parameters exceed 64 KiB. Keep IDs and small explicit configuration only.');
        }
        $body = static::base64($json);

        return 'a3.'.$body.'.'.static::signature($body);
    }

    public static function decode($token): array
    {
        if (!is_string($token) || !str_starts_with($token, 'a3.') || strlen($token) > self::MAX_TOKEN_BYTES) {
            static::fail('action_context_invalid');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !hash_equals(static::signature($parts[1]), $parts[2])) {
            static::fail('action_context_invalid', 403);
        }
        try {
            $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
            if ($json === false || strlen($json) > self::MAX_PAYLOAD_BYTES) {
                static::fail('action_context_invalid');
            }
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            static::fail('action_context_invalid');
        }
        if (!is_array($value) || !isset($value['action'], $value['keys'], $value['source'], $value['id'], $value['mode'])) {
            static::fail('action_context_invalid');
        }

        return $value;
    }

    private static function base64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function signature(string $body): string
    {
        if (!session()->token()) {
            session()->regenerateToken();
        }
        $identity = [(string) (\Encore\Admin\Facades\Admin::user()?->getAuthIdentifier() ?? ''),
            config('admin.auth.guard', 'admin'), config('admin.route.prefix'),
            config('admin.database.connection'), session()->token()];
        $key = hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), app('encrypter')->getKey(), true);

        return static::base64(hash_hmac('sha256', 'a3.'.$body, $key, true));
    }

    public static function fail(string $code, int $status = 409): never
    {
        throw new HttpResponseException(response()->json([
            'status' => false, 'code' => $code, 'message' => trans('admin.'.$code),
        ], $status)->header('Cache-Control', 'no-store, private'));
    }
}
