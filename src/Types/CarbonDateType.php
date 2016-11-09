<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace ChefsPlate\ODM\Types;

use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Doctrine\ODM\MongoDB\Types\Type;
use InvalidArgumentException;

/**
 * The Carbon type.
 *
 * @author      Jonathan H. Wage <jonwage@gmail.com>
 * @author      Roman Borschel <roman@code-factory.org>
 * @author      David Chang <davidchchang@gmail.com>
 */
class CarbonDateType extends Type
{
    const CARBON = 'carbon';

    /**
     * Converts a value to a DateTime.
     * Supports microseconds
     *
     * @throws InvalidArgumentException if $value is invalid
     * @param  mixed $value \DateTime|\MongoDate|int|float
     * @return Carbon
     */
    public static function getCarbon($value)
    {
        $datetime  = false;
        $exception = null;

        if ($value instanceof \DateTime || $value instanceof \DateTimeInterface) {
            if ($value instanceof \DateTimeImmutable) {
                $value = DateTime::createFromFormat('U.u', $value->format('U.u'), $value->getTimezone());
            }
            return Carbon::instance($value);
        } elseif ($value instanceof \MongoDate) {
            $datetime = static::craftDateTime($value->sec, $value->usec);
        } elseif (is_numeric($value)) {
            $seconds      = $value;
            $microseconds = 0;

            if (false !== strpos($value, '.')) {
                list($seconds, $microseconds) = explode('.', $value);
                $microseconds = (int)str_pad((int)$microseconds, 6, '0'); // ensure microseconds
            }

            $datetime = static::craftDateTime($seconds, $microseconds);
        } elseif (is_string($value)) {
            try {
                $datetime = new \DateTime($value);
            } catch (\Exception $e) {
                $exception = $e;
            }
        } elseif (is_object($value) || is_array($value)) {
            $value = (object)$value;
            try {
                $datetime = static::craftDateTime(
                    $value->date->sec,
                    $value->date->usec,
                    new DateTimeZone($value->timezone)
                );
            } catch (\Exception $e) {
                $exception = $e;
            }
        }

        if ($datetime === false) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Could not convert %s to a date value',
                    is_scalar($value) ? '"' . $value . '"' : gettype($value)
                ),
                0,
                $exception
            );
        }

        return Carbon::instance($datetime);
    }

    private static function craftDateTime($seconds, $microseconds = 0, DateTimeZone $timezone = null)
    {
        // all datetimes crafted using seconds + microseconds are relative to UTC unless specified by our custom object
        $utc_timezone = new DateTimeZone("UTC");
        if (is_null($timezone)) {
            $timezone = $utc_timezone;
        }

        $datetime = new \DateTime('now', $utc_timezone);
        $datetime->setTimestamp($seconds);
        if ($microseconds > 0) {
            $datetime = \DateTime::createFromFormat(
                'Y-m-d H:i:s.u',
                $datetime->format('Y-m-d H:i:s') . '.' . $microseconds,
                $utc_timezone
            );
        }
        $datetime->setTimezone($timezone);

        return $datetime;
    }

    public function convertToDatabaseValue($value)
    {
        if ($value === null) {
            return $value;
        }
        $carbon = static::getCarbon($value);
        if ($value instanceof \MongoDate) {
            $mongo_date = $value;
        } else {
            $mongo_date = new \MongoDate($carbon->timestamp, $carbon->micro);
        }

        $result = [
            "date"     => $mongo_date,
            "timezone" => $carbon->getTimezone()->getName(),
            "offset"   => $carbon->getOffset()
        ];

        return (object)$result;
    }

    public function convertToPHPValue($value)
    {
        if ($value === null) {
            return null;
        }

        return static::getCarbon($value);
    }

    public function closureToMongo()
    {
        return
            'if ($value === null || $value instanceof \MongoDate) { $return = $value; } '
            . 'else { $datetime = \\' . get_class($this) . '::getCarbon($value); '
            . '$return = new \MongoDate($datetime->format(\'U\'), $datetime->format(\'u\')); }';
    }

    public function closureToPHP()
    {
        return 'if ($value === null) { $return = null; } '
        . 'else { $return = \\' . get_class($this) . '::getCarbon($value); }';
    }
}
