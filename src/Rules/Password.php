<?php

namespace Encore\Admin\Rules;

use Illuminate\Support\Facades\Validator;

/**
 * Administrator password strength policy.
 *
 * 8-20 characters containing at least one lowercase letter, one uppercase
 * letter, one digit and one special character from `$@!%*?&` (configurable).
 *
 * Implemented as a named validator extension (`admin_password`) rather than a
 * rule object: the legacy `Illuminate\Contracts\Validation\Rule` contract is
 * deprecated and the new `ValidationRule` contract only exists from Laravel
 * 10.13+, while this package supports Laravel 10.0+. A string rule works on
 * every supported version.
 */
class Password
{
    /**
     * Validator extension name registered by `register()`.
     */
    public const RULE = 'admin_password';

    /**
     * Characters accepted as "special" by default.
     */
    public const DEFAULT_SYMBOLS = '$@!%*?&';

    /**
     * @var string
     */
    protected $symbols;

    /**
     * @var int
     */
    protected $min;

    /**
     * @var int
     */
    protected $max;

    /**
     * @param string $symbols
     * @param int    $min
     * @param int    $max
     */
    public function __construct($symbols = self::DEFAULT_SYMBOLS, $min = 8, $max = 20)
    {
        $this->symbols = (string) $symbols;
        $this->min = (int) $min;
        $this->max = (int) $max;
    }

    /**
     * Register the `admin_password` validator extension once.
     *
     * @return void
     */
    public static function register()
    {
        static $registered = false;

        if ($registered) {
            return;
        }
        $registered = true;

        Validator::extend(
            self::RULE,
            static function ($attribute, $value, $parameters = []) {
                return (new static(static::symbolFrom($parameters), ...static::boundsFrom($parameters)))->passesValue($value);
            },
            'admin.password_rule'
        );

        Validator::replacer(
            self::RULE,
            static function ($message, $attribute, $rule, $parameters = []) {
                return (new static(static::symbolFrom($parameters), ...static::boundsFrom($parameters)))->message();
            }
        );
    }

    /**
     * @return string
     */
    public function symbols()
    {
        return $this->symbols;
    }

    /**
     * @param array $parameters
     *
     * @return string
     */
    protected static function symbolFrom(array $parameters)
    {
        return $parameters[2] ?? self::DEFAULT_SYMBOLS;
    }

    /**
     * @param array $parameters
     *
     * @return array
     */
    protected static function boundsFrom(array $parameters)
    {
        return [(int) ($parameters[0] ?? 8), (int) ($parameters[1] ?? 20)];
    }

    /**
     * Whether the value satisfies the strength requirements.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function passesValue($value)
    {
        return is_string($value) && preg_match('/^'.$this->pattern().'$/', $value) === 1;
    }

    /**
     * Regex body without anchors, reusable as an HTML `pattern` attribute so
     * the browser validates before the form is submitted.
     *
     * @return string
     */
    public function pattern()
    {
        $symbols = $this->escapedSymbols();

        return '(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*['.$symbols.'])[a-zA-Z\d'.$symbols.']{'.$this->min.','.$this->max.'}';
    }

    /**
     * HTML5 constraint attributes for client-side validation on submit.
     *
     * @return array
     */
    public function htmlConstraints()
    {
        return [
            'pattern'    => $this->pattern(),
            'minlength'  => $this->min,
            'maxlength'  => $this->max,
            'title'      => $this->message(),
        ];
    }

    /**
     * The `admin_password` string rule, with non-default parameters appended.
     *
     * @return string
     */
    public function rule()
    {
        if ($this->symbols === self::DEFAULT_SYMBOLS && $this->min === 8 && $this->max === 20) {
            return self::RULE;
        }

        return self::RULE.':'.$this->min.','.$this->max.','.$this->symbols;
    }

    /**
     * Minimum accepted length.
     *
     * @return int
     */
    public function min()
    {
        return $this->min;
    }

    /**
     * Maximum accepted length.
     *
     * @return int
     */
    public function max()
    {
        return $this->max;
    }

    /**
     * @return string
     */
    public function message()
    {
        return trans('admin.password_rule', [
            'min'     => $this->min,
            'max'     => $this->max,
            'symbols' => $this->symbols,
        ]);
    }

    /**
     * Regex-safe escaped symbol list usable inside a character class.
     *
     * @return string
     */
    protected function escapedSymbols()
    {
        return preg_quote($this->symbols, '/');
    }
}
