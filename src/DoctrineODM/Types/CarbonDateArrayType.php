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

namespace ChefsPlate\DoctrineODM\Types;

use Doctrine\ODM\MongoDB\MongoDBException;
use Doctrine\ODM\MongoDB\Types\Type;

/**
 * The Carbon array type.
 *
 * @author      David Chang <davidchchang@gmail.com>
 */
class CarbonDateArrayType extends Type
{
    const CARBON_ARRAY = 'carbon_array';

    public function convertToDatabaseValue($value)
    {
        return self::performConversionToDatabaseValue($value);
    }

    public function convertToPHPValue($value)
    {
        return self::performConversionToPHPValue($value);
    }

    public static function performConversionToDatabaseValue($value)
    {
        if ($value === null) {
            return $value;
        }
        if (!is_array($value)) {
            throw MongoDBException::invalidValueForType(self::CARBON_ARRAY, array('array', 'null'), $value);
        }

        $values = array_values($value);
        $result = [];
        foreach ($values as $carbon_date) {
            $carbon = CarbonDateType::getCarbon($carbon_date);
            if ($carbon_date instanceof \MongoDate) {
                $mongo_date = $carbon_date;
            } else {
                $mongo_date = new \MongoDate($carbon->timestamp, $carbon->micro);
            }

            $db_format = [
                "date"     => $mongo_date,
                "timezone" => $carbon->getTimezone()->getName(),
                "offset"   => $carbon->getOffset()
            ];

            $result[] = (object)$db_format;
        }

        return array_values($result);
    }


    public static function performConversionToPHPValue($value)
    {
        if ($value === null) {
            return null;
        }

        $result = [];
        $values = array_values($value);
        foreach ($values as $db_format) {
            $result[] = CarbonDateType::getCarbon($db_format);
        }

        return $result;
    }

    public function closureToMongo()
    {
        return '$return = \\' . get_class($this) . '::performConversionToDatabaseValue($value);';
    }

    public function closureToPHP()
    {
        return '$return = \\' . get_class($this) . '::performConversionToPHPValue($value);';
    }
}
