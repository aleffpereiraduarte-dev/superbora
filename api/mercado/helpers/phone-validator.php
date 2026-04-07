<?php
/**
 * Phone validator helper for Brazilian numbers.
 *
 * validatePhone(string $phone): array
 *   ['valid' => bool, 'normalized' => string, 'ddd' => '11', 'carrier' => 'vivo|tim|claro|oi',
 *    'is_mobile' => bool, 'reason' => 'if invalid']
 *
 * Heuristic only — no external API calls. Operadora is best-effort based on
 * portability rules (mobile prefixes 9xxxx).
 */

if (!function_exists('validatePhone')) {

    function validatePhone(string $phone): array {
        $clean = preg_replace('/\D/', '', $phone);

        // Strip Brazil country code
        if (substr($clean, 0, 2) === '55' && strlen($clean) >= 12) {
            $clean = substr($clean, 2);
        }

        // BR mobile: 11 digits (DDD + 9 + 8 digits) — total 11
        // BR fixed: 10 digits (DDD + 8 digits)
        $len = strlen($clean);
        if ($len !== 10 && $len !== 11) {
            return ['valid' => false, 'reason' => 'comprimento invalido', 'normalized' => $clean];
        }

        $ddd = substr($clean, 0, 2);
        $validDdds = ['11','12','13','14','15','16','17','18','19',  // SP
                      '21','22','24',                                  // RJ
                      '27','28',                                       // ES
                      '31','32','33','34','35','37','38',              // MG
                      '41','42','43','44','45','46',                   // PR
                      '47','48','49',                                  // SC
                      '51','53','54','55',                             // RS
                      '61',                                            // DF
                      '62','64',                                       // GO
                      '63','65','66','67',                             // CO
                      '68','69','71','73','74','75','77',
                      '79','81','82','83','84','85','86','87','88','89',
                      '91','92','93','94','95','96','97','98','99'];
        if (!in_array($ddd, $validDdds, true)) {
            return ['valid' => false, 'reason' => 'DDD invalido', 'normalized' => $clean];
        }

        $rest = substr($clean, 2);
        $isMobile = false;
        if ($len === 11) {
            // Must start with 9
            if ($rest[0] !== '9') {
                return ['valid' => false, 'reason' => 'celular deve comecar com 9', 'normalized' => $clean];
            }
            $isMobile = true;
        } else {
            // Fixed line: must NOT start with 0 or 1
            if (in_array($rest[0], ['0', '1'], true)) {
                return ['valid' => false, 'reason' => 'fixo invalido', 'normalized' => $clean];
            }
        }

        // Reject all-same-digit
        if (preg_match('/^(\d)\1+$/', $clean)) {
            return ['valid' => false, 'reason' => 'todos digitos iguais', 'normalized' => $clean];
        }

        // Reject obviously fake (1234567890, 0987654321)
        if (in_array($clean, ['11999999999', '11111111111', '12345678901', '00000000000'], true)) {
            return ['valid' => false, 'reason' => 'numero conhecidamente fake', 'normalized' => $clean];
        }

        // Carrier hint (best-effort, portability not considered)
        $carrier = null;
        if ($isMobile) {
            $prefix3 = substr($rest, 1, 2);
            $carrierMap = [
                'vivo' => ['96','97','98','99'],
                'tim'  => ['90','91','92','93','94','95'],
                'claro' => ['81','82','83','84','85','86','87','88','89'],
                'oi'    => ['70','71','72','73','74','75','76','77','78','79'],
            ];
            foreach ($carrierMap as $c => $prefixes) {
                if (in_array($prefix3, $prefixes, true)) { $carrier = $c; break; }
            }
        }

        return [
            'valid' => true,
            'normalized' => '55' . $clean,
            'national' => $clean,
            'ddd' => $ddd,
            'is_mobile' => $isMobile,
            'carrier' => $carrier,
            'formatted' => $isMobile
                ? "({$ddd}) " . substr($rest, 0, 1) . substr($rest, 1, 4) . '-' . substr($rest, 5)
                : "({$ddd}) " . substr($rest, 0, 4) . '-' . substr($rest, 4),
        ];
    }
}
