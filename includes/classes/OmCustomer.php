<?php
/**
 * ONEMUNDO CUSTOMER
 * Gerencia cliente/shopper logado usando tabela om_market_shoppers
 */

class OmCustomer {
    private $customer_id = 0;
    private $data = [];
    private $logged = false;

    public function __construct() {
        $this->checkLogin();
    }

    private function checkLogin() {
        // Verificar sessão
        if (!empty($_SESSION['customer_id'])) {
            $this->loadById($_SESSION['customer_id']);
        } elseif (!empty($_SESSION['shopper_id'])) {
            $this->loadById($_SESSION['shopper_id']);
        }
    }

    private function loadById($customer_id) {
        try {
            $db = om_db();

            $stmt = $db->prepare("
                SELECT * FROM om_market_shoppers
                WHERE shopper_id = ? AND status = 1
            ");
            $stmt->execute([$customer_id]);
            $customer = $stmt->fetch();

            if ($customer) {
                $this->customer_id = (int)$customer['shopper_id'];
                $this->data = $customer;
                $this->logged = true;
                $_SESSION['customer_id'] = $this->customer_id;
                $_SESSION['shopper_id'] = $this->customer_id;
            }
        } catch (Exception $e) {
            // Ignorar erro se tabela não existir
        }
    }

    public function login($email, $password = null) {
        try {
            $db = om_db();

            // Login simplificado por email (ou pode adicionar verificação de senha)
            $stmt = $db->prepare("
                SELECT * FROM om_market_shoppers
                WHERE email = ? AND status = 1
            ");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            if ($customer) {
                $this->customer_id = (int)$customer['shopper_id'];
                $this->data = $customer;
                $this->logged = true;
                $_SESSION['customer_id'] = $this->customer_id;
                $_SESSION['shopper_id'] = $this->customer_id;
                return true;
            }
        } catch (Exception $e) {
            // Ignorar
        }

        return false;
    }

    public function loginOrCreate($name, $email, $phone = '', $cpf = '') {
        try {
            $db = om_db();

            // Verificar se já existe
            $stmt = $db->prepare("SELECT * FROM om_market_shoppers WHERE email = ?");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();

            if ($customer) {
                $this->customer_id = (int)$customer['shopper_id'];
                $this->data = $customer;
                $this->logged = true;
            } else {
                // Criar novo shopper
                $stmt = $db->prepare("
                    INSERT INTO om_market_shoppers (name, email, phone, cpf, status, created_at)
                    VALUES (?, ?, ?, ?, 1, NOW())
                ");
                $stmt->execute([$name, $email, $phone, $cpf]);

                $this->customer_id = (int)$db->lastInsertId();
                $this->data = [
                    'shopper_id' => $this->customer_id,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'cpf' => $cpf
                ];
                $this->logged = true;
            }

            $_SESSION['customer_id'] = $this->customer_id;
            $_SESSION['shopper_id'] = $this->customer_id;
            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    public function logout() {
        $this->customer_id = 0;
        $this->data = [];
        $this->logged = false;
        unset($_SESSION['customer_id']);
        unset($_SESSION['shopper_id']);
    }

    public function isLogged() {
        return $this->logged;
    }

    public function getId() {
        return $this->customer_id;
    }

    public function getFirstName() {
        $name = $this->data['name'] ?? '';
        $parts = explode(' ', $name, 2);
        return $parts[0] ?? '';
    }

    public function getLastName() {
        $name = $this->data['name'] ?? '';
        $parts = explode(' ', $name, 2);
        return $parts[1] ?? '';
    }

    public function getFullName() {
        return $this->data['name'] ?? '';
    }

    public function getEmail() {
        return $this->data['email'] ?? '';
    }

    public function getTelephone() {
        return $this->data['phone'] ?? '';
    }

    public function getCpf() {
        return $this->data['cpf'] ?? '';
    }

    public function getSaldo() {
        return (float)($this->data['saldo'] ?? 0);
    }

    public function getRating() {
        return (float)($this->data['rating'] ?? 5);
    }

    public function getGroupId() {
        return 1; // Grupo padrão
    }

    public function getAddressId() {
        return 0; // Não há endereços salvos nesta estrutura
    }

    public function getAddress() {
        // Retornar endereço da sessão se disponível
        return $_SESSION['customer_address'] ?? null;
    }

    public function setAddress($address) {
        $_SESSION['customer_address'] = $address;
    }

    public function getAddresses() {
        // Retornar endereço único da sessão
        $address = $this->getAddress();
        return $address ? [$address] : [];
    }

    public function getData($key = null) {
        if ($key === null) {
            return $this->data;
        }
        return $this->data[$key] ?? null;
    }
}
