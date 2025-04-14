<?php

namespace NeoFramework\Core;
use NumberFormatter;

abstract class Functions
{
    private function __construct()
    {
    }

    /**
     * Returns the root directory of the server
     *
     * @return string The root directory of the server
     */
    public static function getRoot(): string
    {
        return dirname(dirname(dirname(dirname(__DIR__))))."/";
    }

    /**
     * Decodes a URL-encoded UTF-8 string
     *
     * @param string $str The URL-encoded string
     * @return string The decoded string
     */
    public static function utf8UrlDecode(string $str): string
    {
        return mb_convert_encoding(preg_replace("/%u([0-9a-f]{3,4})/i", "&#x\\1;", urldecode($str)), 'UTF-8');
    }

    /**
     * Removes all non-numeric characters from a string
     *
     * @param string $value The string to be filtered
     * @return string The string containing only numbers
     */
    public static function onlyNumber(string $value): string
    {
        return preg_replace("/[^0-9]/", "", $value);
    }

    /**
     * Converts a string to the database date and time format
     *
     * @param string|null $string The date and time string
     * @return string|bool The formatted string or false if it fails
     */
    public static function dateTimeDB(?string $string): string|bool
    {
        if (!$string) {
            return false;
        }

        $string = str_replace("/", "-", $string);
        $datetime = new \DateTimeImmutable($string);
        if ($datetime !== false) {
            return $datetime->format('Y-m-d H:i:s');
        }

        return false;
    }

    /**
     * Converts a string to Brazilian date and time format
     *
     * @param string $string The date and time string
     * @return string|bool The formatted string or false if it fails
     */
    public static function dateTimeBR(string $string): string|bool
    {
        if (!$string) {
            return false;
        }

        $string = str_replace("/", "-", $string);
        $datetime = new \DateTimeImmutable($string);
        if ($datetime !== false) {
            return $datetime->format('d/m/Y H:i:s');
        }

        return false;
    }

    /**
     * Validates if a color is valid
     *
     * @param string $color The color string
     * @return string|bool The color string if valid, false otherwise
     */
    public static function validColor(string $color): string|bool
    {
        $hexPattern = '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/';
        $rgbPattern = '/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/';

        if (preg_match($hexPattern, $color) || preg_match($rgbPattern, $color)) {
            return $color;
        } else {
            return false;
        }
    }

    /**
     * Converts a string to the database date format
     *
     * @param string $string The date string
     * @return string|bool The formatted string or false if it fails
     */
    public static function dateDB(string $string): string|bool
    {
        $datetime = new \DateTimeImmutable($string);
        if ($datetime !== false) {
            return $datetime->format('Y-m-d');
        }

        return false;
    }

    /**
     * Validates if a Base64 string is valid
     *
     * @param string $s The Base64 string
     * @return bool Whether the string is valid
     */
    public static function validBase64(string $s): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $s);
    }

    /**
     * Converts a string to Brazilian date format
     *
     * @param string $string The date string
     * @return string|bool The formatted string or false if it fails
     */
    public static function dateBR(string $string): string|bool
    {
        $datetime = new \DateTimeImmutable($string);
        if ($datetime !== false) {
            return $datetime->format('d/m/Y');
        }

        return false;
    }

    /**
     * Validates if a CPF or CNPJ number is valid
     *
     * @param string|int $cpf_cnpj The CPF or CNPJ
     * @return bool Whether it is valid
     */
    public static function validCpfCnpj($cpf_cnpj): bool
    {
        $cpf_cnpj = preg_replace('/[^0-9]/', '', (string)$cpf_cnpj);

        if (strlen($cpf_cnpj) == 14) {
            return self::validCnpj($cpf_cnpj);
        } elseif (strlen($cpf_cnpj) == 11) {
            return self::validCpf($cpf_cnpj);
        } else {
            return false;
        }
    }

    /**
     * Validates if a CNPJ is valid
     *
     * @param string $cnpj The CNPJ
     * @return bool Whether it is valid
     */
    public static function validCnpj($cnpj): bool
    {
        $cnpj = preg_replace('/[^0-9]/', '', (string)$cnpj);

        // Validates length
        if (strlen($cnpj) != 14) {
            return false;
        }

        // Checks if all digits are the same
        if (preg_match('/(\d)\1{13}/', $cnpj)) {
            return false;
        }

        // Validate the first verification digit
        for ($i = 0, $j = 5, $soma = 0; $i < 12; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }

        $resto = $soma % 11;

        if ($cnpj[12] != ($resto < 2 ? 0 : 11 - $resto)) {
            return false;
        }

        // Validate the second verification digit
        for ($i = 0, $j = 6, $soma = 0; $i < 13; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }

        $resto = $soma % 11;

        return $cnpj[13] == ($resto < 2 ? 0 : 11 - $resto);
    }

    /**
     * Validates if a CPF is valid
     *
     * @param string $cpf The CPF
     * @return bool Whether it is valid
     */
    public static function validCpf($cpf): bool
    {
        // Extracts only the numbers
        $cpf = preg_replace('/[^0-9]/', '', (string)$cpf);

        // Checks if all digits are provided correctly
        if (strlen($cpf) != 11) {
            return false;
        }

        // Checks if the sequence is composed of repeated digits
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        // Performs calculation to validate CPF
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }

        return true;
    }

    /**
     * Formats a CNPJ or CPF
     *
     * @param string|null $value The CNPJ or CPF value
     * @return string|bool The formatted value or false if invalid
     */
    public static function formatCpfCnpj(?string $value): string|bool
    {
        if (!$value) {
            return false;
        }

        $CPF_LENGTH = 11;
        $cnpj_cpf = preg_replace("/\D/", '', $value);

        if (strlen($cnpj_cpf) === $CPF_LENGTH) {
            return self::applyMask($cnpj_cpf, '###.###.###-##');
        } else {
            return self::applyMask($cnpj_cpf, '##.###.###/####-##');
        }
    }

    /**
     * Applies a mask to a string
     *
     * @param string $val The original string
     * @param string $mask The mask to apply
     * @return string The masked string
     */
    public static function applyMask(string $val, string $mask): string
    {
        $masked = '';
        $k = 0;
        for ($i = 0; $i <= strlen($mask) - 1; $i++) {
            if ($mask[$i] == '#') {
                if (isset($val[$k])) {
                    $masked .= $val[$k++];
                }
            } else {
                if (isset($mask[$i])) {
                    $masked .= $mask[$i];
                }
            }
        }
        return $masked;
    }

    /**
     * Validates if an email is valid
     *
     * @param string $email The email string
     * @return bool Whether it is valid
     */
    public static function validEmail($email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }


    /**
     * Validates if a time is valid
     *
     * @param string $time The time string
     * @return bool Whether it is valid
     */
    public static function validateTime(string $time): bool
    {
        // Regular expression to validate HH:MM:SS format
        $timePattern = '/^([01]?[0-9]|2[0-3]):([0-5]?[0-9]):([0-5]?[0-9])$/';

        // Check if the time matches the pattern
        if (preg_match($timePattern, $time, $matches)) {
            $hour = intval($matches[1]);
            $minute = intval($matches[2]);
            $second = intval($matches[3]);

            return $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $second >= 0 && $second <= 59;
        }

        return false;
    }

    /**
     * Formats a time string to the HH:MM:SS or HH:MM format
     *
     * @param string $time The time string to format
     * @return string The formatted time string
     */
    public static function formatTimeString(string $time): string
    {
        $count = substr_count($time, ":");

        if ($count > 1) {
            return $time;
        } elseif ($count == 1) {
            return $time . ":00";
        } else {
            return $time . ":00:00";
        }
    }

    /**
     * Multiplies a time by a given quantity
     *
     * @param string $time The time string to multiply
     * @param int $quantity The quantity to multiply the time by
     * @return string The new time string in HH:MM:SS format
     */
    public static function multiplyTime(string $time, int $quantity): string
    {
        list($hours, $minutes, $seconds) = explode(':', $time);

        // Convert everything to seconds
        $totalSeconds = $hours * 3600 + $minutes * 60 + $seconds;

        // Multiply the total seconds by the given quantity
        $totalSeconds *= $quantity;

        // Calculate new hours, minutes, and seconds
        $newHours = floor($totalSeconds / 3600);
        $totalSeconds %= 3600;
        $newMinutes = floor($totalSeconds / 60);
        $newSeconds = $totalSeconds % 60;

        // Return the new time in HH:MM:SS format
        return sprintf('%02d:%02d:%02d', $newHours, $newMinutes, $newSeconds);
    }

    /**
     * Removes the seconds from a time string
     *
     * @param string $time The time string to modify
     * @return string The time string without seconds
     */
    public static function removeSecondsFromTime($time): string
    {
        if ($count = substr_count($time, ":")) {
            if ($count == 2) {
                $time = explode(":", $time);
                return $time[0] . ":" . $time[1];
            }
            if ($count == 1) {
                return $time;
            }
        } else {
            return $time . ":00";
        }

        return $time;
    }

    /**
     * Formats a monetary value to Brazilian currency format
     *
     * @param float|int $input The monetary value
     * @return string The formatted monetary value
     */
    public static function formatCurrencyValue(float|int $input,$countryCode = "pt-BR"): string
    {
        $fmt = new NumberFormatter($countryCode, NumberFormatter::CURRENCY);
        return $fmt->format($input);
    }

    /**
     * Removes the currency formatting and returns a numeric value
     *
     * @param string $input The formatted monetary value
     * @return float The numeric value
     */
    public static function removeCurrencyFormatting($input): string
    {
        return floatval(str_replace(",", ".", preg_replace("/[^0-9.,]/", "", $input)));
    }

    /**
     * Generates a random code based on random bytes
     *
     * @param int $number The number of bytes to generate the code
     * @return string The generated code
     */
    public static function generateRandomCode($number): string
    {
        return substr(strtoupper(substr(bin2hex(random_bytes($number)), 1)), 0, $number);
    }

    /**
     * Formats an IP address to the format XXX.XXX.XXX.XXX
     *
     * @param string $ip The IP address to format
     * @return string|bool The formatted IP address or false if invalid
     */
    public static function formatIpAddress($ip): string
    {
        $ip = preg_replace('/\D/', '', $ip);
        $length = strlen($ip);

        // Validate if the IP has 12 digits
        if ($length < 4 || $length > 12) {
            return false;
        }

        // Format the IP address to the desired format (XXX.XXX.XXX.XXX)
        return sprintf("%03d.%03d.%03d.%03d", substr($ip, 0, 3), substr($ip, 3, 3), substr($ip, 6, 3), substr($ip, 9, 3));
    }

    /**
     * Validates a postal code to the XXXXX or XXXXX-XXX format
     *
     * @param string $cep The postal code to validate
     * @return bool Whether the postal code is valid
     */
    public static function validCep($cep): bool
    {
        if (preg_match('/^[0-9]{5,5}([- ]?[0-9]{3,3})?$/', $cep)) {
            return true;
        }
        return false;
    }

    /**
     * Validates a phone number to the (XX) XXXX-XXXX or (XX) XXXXX-XXXX format
     *
     * @param string $phone The phone number to validate
     * @return bool Whether the phone number is valid
     */
    public static function validPhoneNumber($phone): bool
    {
        $phone = preg_replace('/\D/', '', $phone);

        // Validate if the phone number has the correct length
        if (strlen($phone) != 10 && strlen($phone) != 11) {
            return false; // Return false if the length is invalid
        }

        return true;
    }

    /**
     * Formats a phone number
     *
     * @param string $phone The phone number
     * @return string|bool The formatted phone number or false if invalid
     */
    public static function formatPhoneNumber($phone): string|bool
    {
        if (!$phone)
            return false;

        $phone = self::onlyNumber($phone);

        if (strlen($phone) == 10)
            return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 4) . '-' . substr($phone, 6);
        else
            return '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 5) . '-' . substr($phone, 7);
    }

    /**
     * Formats a postal code
     *
     * @param string $cep The postal code
     * @return string|bool The formatted postal code or false if invalid
     */
    public static function formatCep(string $cep): string|bool
    {
        if (!$cep)
            return false;

        $cep = self::onlyNumber($cep);

        if (strlen($cep) == 8)
            return substr($cep, 0, 5) . '-' . substr($cep, 5, 3);

        return false;
    }

    /**
     * Removes accents from a string
     *
     * @param string $string The string to process
     * @return string The string without accents
     */
    public static function removeAccents(string $string)
    {
        return preg_replace(array("/(á|à|ã|â|ä)/", "/(Á|À|Ã|Â|Ä)/", "/(é|è|ê|ë)/", "/(É|È|Ê|Ë)/", "/(í|ì|î|ï)/", "/(Í|Ì|Î|Ï)/", "/(ó|ò|õ|ô|ö)/", "/(Ó|Ò|Õ|Ô|Ö)/", "/(ú|ù|û|ü)/", "/(Ú|Ù|Û|Ü)/", "/(ñ)/", "/(Ñ)/"), explode(" ", "a A e E i I o O u U n N"), $string);
    }

    /**
     * Validates a DRE code
     *
     * @param string $code The DRE code to validate
     * @return bool Whether the DRE code is valid
     */
    public static function validDre($code)
    {
        return preg_match('/^(?:\d)(?:\.\d){0,2}(?:\.\d{1,2})?$/', $code);
    }

    /**
     * Formats a DRE code
     *
     * @param string $code The DRE code to format
     * @return string The formatted DRE code
     */
    public static function formatDre(string $code)
    {
        $code = strval($code);
        $parts = str_split($code, 1);
        $i = 1;
        $b = 1;
        $finalPart = "";
        foreach ($parts as $part) {
            $finalPart .= $i > 3 ? $part : $part . ".";
            $i++;

            if ($i > 3) {
                if ($b > 2) {
                    $finalPart .= ".";
                    $b = 1;
                }
                $b++;
            }
        }
        return rtrim($finalPart, ".");
    }

    /**
     * Adds time to an initial time value
     *
     * @param string $initialTime The initial time (HH:MM:SS)
     * @param string $timeToAdd The time to add (HH:MM:SS)
     * @return string The resulting time after adding
     */
    public static function addTime(string $initialTime,string $timeToAdd)
    {
        try {
            $datetime = new \DateTime($initialTime);

            list($hours, $minutes, $seconds) = explode(':', $timeToAdd);

            $interval = new \DateInterval("PT{$hours}H{$minutes}M{$seconds}S");

            $datetime->add($interval);

            return $datetime->format('H:i:s');
        } catch (\Exception $e) {
            Logger::error($e->getMessage() . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Subtracts time from an initial time value
     *
     * @param string $initialTime The initial time (HH:MM:SS)
     * @param string $timeToSubtract The time to subtract (HH:MM:SS)
     * @return string The resulting time after subtraction
     */
    public static function subtractTime(string $initialTime,string $timeToSubtract)
    {
        try {
            $datetime = new \DateTime($initialTime);

            list($hours, $minutes, $seconds) = explode(':', $timeToSubtract);

            $interval = new \DateInterval("PT{$hours}H{$minutes}M{$seconds}S");

            $datetime->sub($interval);

            return $datetime->format('H:i:s');
        } catch (\Exception $e) {
            Logger::error($e->getMessage() . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Adds a time interval to a date
     *
     * @param string $initialDate The initial date (Y-m-d H:i:s)
     * @param string $timeToAdd The time interval to add (HH:MM:SS)
     * @return string The resulting date after adding the interval
     */
    public static function addDateTime(string $initialDate,string $timeToAdd)
    {
        try {
            $datetime = new \DateTime($initialDate);

            list($hours, $minutes, $seconds) = explode(':', $timeToAdd);

            $interval = new \DateInterval("PT{$hours}H{$minutes}M{$seconds}S");

            $datetime->add($interval);

            return $datetime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Logger::error($e->getMessage() . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Subtracts a time interval from a date
     *
     * @param string $initialDate The initial date (Y-m-d H:i:s)
     * @param string $timeToSubtract The time interval to subtract (HH:MM:SS)
     * @return string The resulting date after subtracting the interval
     */
    public static function subtractDateTime(string $initialDate,string $timeToSubtract)
    {
        try {
            $datetime = new \DateTime($initialDate);

            list($hours, $minutes, $seconds) = explode(':', $timeToSubtract);

            $interval = new \DateInterval("PT{$hours}H{$minutes}M{$seconds}S");

            $datetime->sub($interval);

            return $datetime->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Logger::error($e->getMessage() . $e->getTraceAsString());
            return null;
        }
    }

    /**
     * Converts a relative file path to an absolute path
     *
     * @param string $path The file path to convert
     * @return string The absolute file path
     */
    public static function getAbsolutePath(string $path)
    {
        $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
        $absolutes = array();
        foreach ($parts as $part) {
            if ('.' == $part) continue;
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        return implode(DIRECTORY_SEPARATOR, $absolutes);
    }

    /**
     * Generates a unique ID based on the current microtime
     *
     * @return int The generated ID
     */
    public static function generateId()
    {
        return intval(self::onlyNumber(microtime()));
    }

    /**
     * Retrieves the user's IP address
     *
     * @return string The IP address of the user
     */
    public static function getUserIp()
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && isset($_SERVER['REMOTE_ADDR'])) {
            $client_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return array_shift($client_ips);
        } elseif (isset($_SERVER['HTTP_CLIENT_IP']) && isset($_SERVER['REMOTE_ADDR'])) {
            $client_ips = explode(',', $_SERVER['HTTP_CLIENT_IP']);
            return array_shift($client_ips);
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    /**
     * Checks if the current user is on a mobile device
     *
     * @return bool True if the user is on a mobile device, otherwise false
     */
    public static function isMobile(): bool
    {
        $useragent = $_SERVER['HTTP_USER_AGENT'];

        return preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $useragent) || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($useragent, 0, 4));
    }

    /**
     * Generates a slug for a given string
     *
     * @param string $text The text to be converted to a slug
     * @return string The generated slug
     */
    public static function slug(string $text)
    {
        $replace = [
            '<' => '', '>' => '', '-' => ' ', '&' => '',
            '"' => '', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'Ae',
            'Ä' => 'A', 'Å' => 'A', 'Ā' => 'A', 'Ą' => 'A', 'Ă' => 'A', 'Æ' => 'Ae',
            'Ç' => 'C', 'Ć' => 'C', 'Č' => 'C', 'Ĉ' => 'C', 'Ċ' => 'C', 'Ď' => 'D', 'Đ' => 'D',
            'Ð' => 'D', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ē' => 'E',
            'Ę' => 'E', 'Ě' => 'E', 'Ĕ' => 'E', 'Ė' => 'E', 'Ĝ' => 'G', 'Ğ' => 'G',
            'Ġ' => 'G', 'Ģ' => 'G', 'Ĥ' => 'H', 'Ħ' => 'H', 'Ì' => 'I', 'Í' => 'I',
            'Î' => 'I', 'Ï' => 'I', 'Ī' => 'I', 'Ĩ' => 'I', 'Ĭ' => 'I', 'Į' => 'I',
            'İ' => 'I', 'Ĳ' => 'IJ', 'Ĵ' => 'J', 'Ķ' => 'K', 'Ł' => 'K', 'Ľ' => 'K',
            'Ĺ' => 'K', 'Ļ' => 'K', 'Ŀ' => 'K', 'Ñ' => 'N', 'Ń' => 'N', 'Ň' => 'N',
            'Ņ' => 'N', 'Ŋ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ö' => 'Oe', 'Ö' => 'Oe', 'Ø' => 'O', 'Ō' => 'O', 'Ő' => 'O', 'Ŏ' => 'O',
            'Œ' => 'OE', 'Ŕ' => 'R', 'Ř' => 'R', 'Ŗ' => 'R', 'Ś' => 'S', 'Š' => 'S',
            'Ş' => 'S', 'Ŝ' => 'S', 'Ș' => 'S', 'Ť' => 'T', 'Ţ' => 'T', 'Ŧ' => 'T',
            'Ț' => 'T', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'Ue', 'Ū' => 'U',
            'Ü' => 'Ue', 'Ů' => 'U', 'Ű' => 'U', 'Ŭ' => 'U', 'Ũ' => 'U', 'Ų' => 'U',
            'Ŵ' => 'W', 'Ý' => 'Y', 'Ŷ' => 'Y', 'Ÿ' => 'Y', 'Ź' => 'Z', 'Ž' => 'Z',
            'Ż' => 'Z', 'Þ' => 'T', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a',
            'ä' => 'ae', 'ä' => 'ae', 'å' => 'a', 'ā' => 'a', 'ą' => 'a', 'ă' => 'a',
            'æ' => 'ae', 'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ĉ' => 'c', 'ċ' => 'c',
            'ď' => 'd', 'đ' => 'd', 'ð' => 'd', 'è' => 'e', 'é' => 'e', 'ê' => 'e',
            'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e', 'ĕ' => 'e', 'ė' => 'e',
            'ƒ' => 'f', 'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h',
            'ħ' => 'h', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
            'ĩ' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i', 'ĳ' => 'ij', 'ĵ' => 'j',
            'ķ' => 'k', 'ĸ' => 'k', 'ł' => 'l', 'ľ' => 'l', 'ĺ' => 'l', 'ļ' => 'l',
            'ŀ' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ņ' => 'n', 'ŉ' => 'n',
            'ŋ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'oe',
            'ö' => 'oe', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o', 'ŏ' => 'o', 'œ' => 'oe',
            'ŕ' => 'r', 'ř' => 'r', 'ŗ' => 'r', 'š' => 's', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'ue', 'ū' => 'u', 'ü' => 'ue', 'ů' => 'u', 'ű' => 'u',
            'ŭ' => 'u', 'ũ' => 'u', 'ų' => 'u', 'ŵ' => 'w', 'ý' => 'y', 'ÿ' => 'y',
            'ŷ' => 'y', 'ž' => 'z', 'ż' => 'z', 'ź' => 'z', 'þ' => 't', 'ß' => 'ss',
            'ſ' => 'ss', 'ый' => 'iy', 'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G',
            'Д' => 'D', 'Е' => 'E', 'Ё' => 'YO', 'Ж' => 'ZH', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O',
            'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F',
            'Х' => 'H', 'Ц' => 'C', 'Ч' => 'CH', 'Ш' => 'SH', 'Щ' => 'SCH', 'Ъ' => '',
            'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'YU', 'Я' => 'YA', 'а' => 'a',
            'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l',
            'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
            'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e',
            'ю' => 'yu', 'я' => 'ya'
        ];

        // Make a human-readable string
        $text = strtr($text, $replace);

        // Replace non-letter or digits with a space
        $text = preg_replace('~[^\pL\d.]+~u', '-', $text);

        // Trim spaces
        $text = trim($text, '-');

        // Remove unwanted characters
        $text = preg_replace('~[^-\w.]+~', '', $text);

        return strtolower($text);
    }
}
