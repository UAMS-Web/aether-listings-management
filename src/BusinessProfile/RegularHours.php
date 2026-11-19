<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\People\ListingsManagement\BusinessProfile;

/**
 * A location's `regular_hours` rules as Business Profile's `regularHours` periods.
 *
 * The rules are read the way the site reads them for a visitor
 * (`App\Tags\LocationRegularHours` in UAMS-Web/uams-statamic), so the listing
 * and the page cannot disagree about one stored value:
 *
 * - Only in-person rules count. A `telemedicine` rule is skipped, because a
 *   listing's hours describe the premises; an absent or unknown service mode is
 *   in person, the blueprint's default.
 * - Where two rules name the same day, the later rule wins, so an editor can add
 *   a Daily rule and then a Custom rule for the day that differs.
 * - `closed` makes its days closed. A closed day has no period, which is how
 *   Business Profile says a day is closed.
 * - `open_24h` is one period from 00:00 to 24:00.
 * - Any other status, or none, is open during the rule's time spans. A span
 *   needs an opening and a closing time as `HH:MM` (seconds are ignored), and
 *   the two must differ; a span without them is dropped, and an open rule left
 *   with no span changes no day.
 * - A span that closes at 00:00 closes at the end of its day (24:00). One that
 *   closes earlier than it opens closes the next day.
 * - A rule or span marked as not enabled is skipped.
 */
final class RegularHours
{
    /** Day keys in week order, with Business Profile's name for each. */
    public const array DAYS = [
        'monday' => 'MONDAY',
        'tuesday' => 'TUESDAY',
        'wednesday' => 'WEDNESDAY',
        'thursday' => 'THURSDAY',
        'friday' => 'FRIDAY',
        'saturday' => 'SATURDAY',
        'sunday' => 'SUNDAY',
    ];

    /** Minutes in a day: a closing time of 24:00. */
    private const int DAY_END = 1440;

    /**
     * The `regularHours` field for a set of rules, or null when no day is open.
     *
     * @param  mixed  $rules  The `regular_hours` replicator value: a list of rules with `days`, `service_mode`, `status` and `time_span`.
     * @return ?array{periods: list<array{openDay: string, openTime: array{hours: int, minutes: int}, closeDay: string, closeTime: array{hours: int, minutes: int}}>}
     */
    public static function fromRules(mixed $rules): ?array
    {
        // Day key => the spans that day is open, in minutes from midnight; a closed day has none
        $week = [];

        foreach (self::enabled($rules) as $rule) {
            if (Values::str($rule['service_mode'] ?? null) === 'telemedicine') {
                continue;
            }

            $spans = self::spans($rule);

            foreach ($spans === null ? [] : self::days($rule['days'] ?? null) as $day) {
                $week[$day] = $spans;
            }
        }

        $periods = [];
        $days = array_values(self::DAYS);

        foreach (array_keys(self::DAYS) as $index => $day) {
            foreach ($week[$day] ?? [] as [$open, $close]) {
                $periods[] = [
                    'openDay' => $days[$index],
                    'openTime' => self::timeOfDay($open),
                    // A span that closes before it opens runs past midnight. A span
                    // never opens and closes at the same minute (spans() drops one),
                    // so `<` and `<=` cannot be told apart here.
                    // @pest-mutate-ignore: SmallerToSmallerOrEqual
                    'closeDay' => $days[($index + ($close < $open ? 1 : 0)) % 7],
                    'closeTime' => self::timeOfDay($close),
                ];
            }
        }

        return $periods === [] ? null : ['periods' => $periods];
    }

    /**
     * A time of day as minutes from midnight, or null when it is not a time.
     *
     * @param  mixed  $value  A time field's value, `HH:MM` or `HH:MM:SS`.
     */
    public static function minutes(mixed $value): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d\d)(?::\d\d)?$/', trim(Values::str($value)), $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        return $hours < 24 && $minutes < 60 ? $hours * 60 + $minutes : null;
    }

    /**
     * The spans a rule opens its days for: [open, close] in minutes, none for a closed rule, or null when it sets nothing.
     *
     * @param  array<array-key, mixed>  $rule  One rule.
     * @return ?list<array{int, int}>
     */
    private static function spans(array $rule): ?array
    {
        $status = trim(Values::str($rule['status'] ?? null));

        if ($status === 'closed') {
            return [];
        }

        if ($status === 'open_24h') {
            return [[0, self::DAY_END]];
        }

        $spans = [];

        foreach (self::enabled($rule['time_span'] ?? null) as $span) {
            $open = self::minutes($span['open'] ?? null);
            $close = self::minutes($span['close'] ?? null);

            if ($open !== null && $close !== null && $open !== $close) {
                $spans[] = [$open, $close === 0 ? self::DAY_END : $close];
            }
        }

        return $spans === [] ? null : $spans;
    }

    /**
     * The day keys a rule names, in its order: each a string or an option with a `key` or `value`.
     *
     * @param  mixed  $days  The rule's `days` value.
     * @return list<string>
     */
    private static function days(mixed $days): array
    {
        $keys = [];

        foreach (Values::arr($days) as $day) {
            $key = trim(is_array($day) ? Values::str($day['key'] ?? $day['value'] ?? null) : Values::str($day));

            if (array_key_exists($key, self::DAYS)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The rows of a replicator value, without those an editor turned off.
     *
     * @param  mixed  $rows  A replicator value.
     * @return array<int, array<array-key, mixed>>
     */
    private static function enabled(mixed $rows): array
    {
        return array_filter(Values::arrays($rows), static fn (array $row): bool => ($row['enabled'] ?? true) !== false);
    }

    /**
     * Minutes from midnight as a Business Profile time of day.
     *
     * Both parts are always sent, so midnight is an object rather than an empty JSON array.
     *
     * @param  int  $minutes  Minutes from midnight, up to 1440.
     * @return array{hours: int, minutes: int}
     */
    private static function timeOfDay(int $minutes): array
    {
        return ['hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60];
    }
}
