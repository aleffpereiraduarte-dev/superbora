<?php
/**
 * ONEMUNDO CONFIG
 * Configuracoes da loja (100% independente do OpenCart)
 */

class OmConfig {
    private $data = [];

    public function __construct() {
        $this->load();
    }

    private function load() {
        // Carregar de variáveis de ambiente ou usar padrões
        $this->data = [
            'config_name' => env('STORE_NAME', 'SuperBora'),
            'config_url' => env('STORE_URL', 'https://onemundo.store'),
            'config_email' => env('STORE_EMAIL', 'contato@onemundo.store'),
            'config_telephone' => env('STORE_PHONE', ''),
            'config_logo' => env('STORE_LOGO', 'logo.png'),
            'config_language_id' => 1,
            'config_currency' => 'BRL',
            'config_country_id' => 30, // Brasil
            'config_zone_id' => 467 // São Paulo
        ];

        // Tentar carregar configurações adicionais do banco se existir tabela
        try {
            $db = om_db();
            // Verificar se tabela de configuração existe
            $stmt = $db->query("SHOW TABLES LIKE 'om_config'");
            if ($stmt->fetch()) {
                $result = $db->query("SELECT `key`, `value` FROM om_config WHERE active = 1");
                while ($row = $result->fetch()) {
                    $this->data[$row['key']] = $row['value'];
                }
            }
        } catch (Exception $e) {
            // Ignorar erros de banco, usar valores padrão
        }
    }

    public function get($key, $default = null) {
        return $this->data[$key] ?? $default;
    }

    public function set($key, $value) {
        $this->data[$key] = $value;
    }

    // Helpers comuns
    public function getStoreName() {
        return $this->get('config_name', 'SuperBora');
    }

    public function getStoreUrl() {
        return $this->get('config_url', 'https://onemundo.store');
    }

    public function getLogo() {
        $logo = $this->get('config_logo');
        return $logo ? 'image/' . $logo : '';
    }

    public function getEmail() {
        return $this->get('config_email', '');
    }

    public function getTelephone() {
        return $this->get('config_telephone', '');
    }

    public function getLanguageId() {
        return (int)$this->get('config_language_id', 1);
    }

    public function getCurrency() {
        return $this->get('config_currency', 'BRL');
    }

    public function getCountryId() {
        return (int)$this->get('config_country_id', 30);
    }

    public function getZoneId() {
        return (int)$this->get('config_zone_id', 467);
    }
}
