<?php
/**
 * ONEMUNDO CART
 * Gerencia carrinho de compras usando tabelas om_market_*
 */

class OmCart {
    private $customer;
    private $session_id;

    public function __construct(OmCustomer $customer) {
        $this->customer = $customer;
        $this->session_id = $_SESSION['om_session_id'] ?? session_id();
    }

    /**
     * Retorna produtos do carrinho
     */
    public function getProducts() {
        $db = om_db();

        $sql = "
            SELECT c.id as cart_id, c.product_id, c.quantity, c.price as cart_price, c.notes,
                   p.name, p.description, p.image, p.price, p.special_price,
                   p.quantity as stock, p.unit, p.partner_id
            FROM om_market_cart c
            LEFT JOIN om_market_products p ON c.product_id = p.product_id
            WHERE p.status = 1
        ";

        // Por sessao ou customer_id
        if ($this->customer->isLogged()) {
            $sql .= " AND (c.customer_id = ? OR c.session_id = ?)";
            $params = [$this->customer->getId(), $this->session_id];
        } else {
            $sql .= " AND c.session_id = ?";
            $params = [$this->session_id];
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $products = [];
        foreach ($rows as $row) {
            // Usar preço especial se disponível
            $price = $row['cart_price'] ?: ($row['special_price'] ?: $row['price']);
            $quantity = (int)$row['quantity'];

            $products[] = [
                'cart_id' => (int)$row['cart_id'],
                'product_id' => (int)$row['product_id'],
                'partner_id' => (int)$row['partner_id'],
                'name' => $row['name'],
                'model' => $row['unit'] ?? 'un',
                'image' => $row['image'],
                'option' => [],
                'notes' => $row['notes'] ?? '',
                'quantity' => $quantity,
                'minimum' => 1,
                'stock' => (int)$row['stock'],
                'price' => round((float)$price, 2),
                'total' => round((float)$price * $quantity, 2)
            ];
        }

        return $products;
    }

    /**
     * Adiciona produto ao carrinho
     */
    public function add($product_id, $quantity = 1, $options = []) {
        $db = om_db();

        $product_id = (int)$product_id;
        $quantity = max(1, (int)$quantity);
        $notes = $options['notes'] ?? '';

        // Buscar dados do produto
        $stmt = $db->prepare("SELECT partner_id, price, special_price FROM om_market_products WHERE product_id = ? AND status = 1");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if (!$product) {
            return false;
        }

        $price = $product['special_price'] ?: $product['price'];
        $partner_id = $product['partner_id'];

        // Verificar se produto já existe no carrinho
        if ($this->customer->isLogged()) {
            $stmt = $db->prepare("SELECT id, quantity FROM om_market_cart WHERE customer_id = ? AND product_id = ?");
            $stmt->execute([$this->customer->getId(), $product_id]);
        } else {
            $stmt = $db->prepare("SELECT id, quantity FROM om_market_cart WHERE session_id = ? AND product_id = ?");
            $stmt->execute([$this->session_id, $product_id]);
        }
        $existing = $stmt->fetch();

        if ($existing) {
            // Atualizar quantidade
            $new_qty = $existing['quantity'] + $quantity;
            $stmt = $db->prepare("UPDATE om_market_cart SET quantity = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_qty, $existing['id']]);
        } else {
            // Inserir novo
            $stmt = $db->prepare("
                INSERT INTO om_market_cart (session_id, customer_id, partner_id, product_id, quantity, price, notes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $customer_id = $this->customer->isLogged() ? $this->customer->getId() : 0;
            $stmt->execute([$this->session_id, $customer_id, $partner_id, $product_id, $quantity, $price, $notes]);
        }

        return true;
    }

    /**
     * Atualiza quantidade de um item
     */
    public function update($cart_id, $quantity) {
        $db = om_db();

        $cart_id = (int)$cart_id;
        $quantity = (int)$quantity;

        if ($quantity <= 0) {
            return $this->remove($cart_id);
        }

        // Verificar se pertence ao usuário
        if ($this->customer->isLogged()) {
            $stmt = $db->prepare("UPDATE om_market_cart SET quantity = ?, updated_at = NOW() WHERE id = ? AND (customer_id = ? OR session_id = ?)");
            $stmt->execute([$quantity, $cart_id, $this->customer->getId(), $this->session_id]);
        } else {
            $stmt = $db->prepare("UPDATE om_market_cart SET quantity = ?, updated_at = NOW() WHERE id = ? AND session_id = ?");
            $stmt->execute([$quantity, $cart_id, $this->session_id]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Remove item do carrinho
     */
    public function remove($cart_id) {
        $db = om_db();
        $cart_id = (int)$cart_id;

        if ($this->customer->isLogged()) {
            $stmt = $db->prepare("DELETE FROM om_market_cart WHERE id = ? AND (customer_id = ? OR session_id = ?)");
            $stmt->execute([$cart_id, $this->customer->getId(), $this->session_id]);
        } else {
            $stmt = $db->prepare("DELETE FROM om_market_cart WHERE id = ? AND session_id = ?");
            $stmt->execute([$cart_id, $this->session_id]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Limpa o carrinho
     */
    public function clear() {
        $db = om_db();

        if ($this->customer->isLogged()) {
            $stmt = $db->prepare("DELETE FROM om_market_cart WHERE customer_id = ? OR session_id = ?");
            $stmt->execute([$this->customer->getId(), $this->session_id]);
        } else {
            $stmt = $db->prepare("DELETE FROM om_market_cart WHERE session_id = ?");
            $stmt->execute([$this->session_id]);
        }
    }

    /**
     * Verifica se tem produtos
     */
    public function hasProducts() {
        return count($this->getProducts()) > 0;
    }

    /**
     * Conta total de itens
     */
    public function countProducts() {
        $total = 0;
        foreach ($this->getProducts() as $product) {
            $total += $product['quantity'];
        }
        return $total;
    }

    /**
     * Subtotal do carrinho
     */
    public function getSubTotal() {
        $total = 0;
        foreach ($this->getProducts() as $product) {
            $total += $product['total'];
        }
        return round($total, 2);
    }

    /**
     * Total do carrinho
     */
    public function getTotal() {
        return $this->getSubTotal();
    }

    /**
     * Agrupa produtos por partner (loja)
     */
    public function getProductsByPartner() {
        $products = $this->getProducts();
        $grouped = [];

        foreach ($products as $product) {
            $partner_id = $product['partner_id'];
            if (!isset($grouped[$partner_id])) {
                $grouped[$partner_id] = [];
            }
            $grouped[$partner_id][] = $product;
        }

        return $grouped;
    }

    /**
     * Mescla carrinho de sessão com carrinho do cliente ao fazer login
     */
    public function merge() {
        if (!$this->customer->isLogged()) {
            return;
        }

        $db = om_db();
        $customerId = $this->customer->getId();

        // Get session cart items (anonymous)
        $stmtSession = $db->prepare("SELECT id, product_id, quantity FROM om_market_cart WHERE session_id = ? AND customer_id = 0");
        $stmtSession->execute([$this->session_id]);
        $sessionItems = $stmtSession->fetchAll();

        foreach ($sessionItems as $item) {
            // Check if customer already has this product
            $stmtExist = $db->prepare("SELECT id, quantity FROM om_market_cart WHERE customer_id = ? AND product_id = ?");
            $stmtExist->execute([$customerId, $item['product_id']]);
            $existing = $stmtExist->fetch();

            if ($existing) {
                // Sum quantities and remove the session duplicate
                $newQty = (int)$existing['quantity'] + (int)$item['quantity'];
                $db->prepare("UPDATE om_market_cart SET quantity = ?, updated_at = NOW() WHERE id = ?")->execute([$newQty, $existing['id']]);
                $db->prepare("DELETE FROM om_market_cart WHERE id = ?")->execute([$item['id']]);
            } else {
                // Assign session item to customer
                $db->prepare("UPDATE om_market_cart SET customer_id = ? WHERE id = ?")->execute([$customerId, $item['id']]);
            }
        }
    }
}
