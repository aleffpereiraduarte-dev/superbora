<?php
/**
 * Page Builder Frontend Controller - Mercado OneMundo
 * Renderiza páginas customizadas no frontend
 */

class ControllerMercadoPage extends Controller {
    
    /**
     * Exibir página por slug
     */
    public function index() {
        $slug = isset($this->request->get['slug']) ? $this->request->get['slug'] : '';
        
        $this->load->model('mercado/page_builder');
        
        if ($slug) {
            $page = $this->model_mercado_page_builder->getPageBySlug($slug);
        } else {
            // Se não tiver slug, tentar carregar homepage
            $page = $this->model_mercado_page_builder->getHomepage();
        }
        
        if (!$page) {
            // Página não encontrada
            $this->response->redirect($this->url->link('error/not_found'));
            return;
        }
        
        // Configurar meta tags
        $this->document->setTitle($page['title']);
        if (!empty($page['description'])) {
            $this->document->setDescription($page['description']);
        }
        
        // Carregar CSS do Page Builder
        $this->document->addStyle('catalog/view/theme/default/stylesheet/page-builder.css');
        
        // Decodificar JSON
        $layout = json_decode($page['layout_json'], true);
        $settings = json_decode($page['settings_json'], true);
        
        // Obter blocos globais
        $global_blocks = $this->model_mercado_page_builder->getGlobalBlocks();
        
        // Renderizar seções
        $data['content'] = $this->renderSections($layout['sections'] ?? [], $settings);
        $data['page'] = $page;
        $data['settings'] = $settings;
        
        // Aplicar CSS customizado
        $data['custom_css'] = $this->generateCustomCSS($settings);
        
        // Carregar header/footer padrão ou customizado
        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');
        
        $this->response->setOutput($this->load->view('mercado/page', $data));
    }
    
    /**
     * Renderizar todas as seções
     */
    private function renderSections($sections, $settings) {
        $html = '';
        
        foreach ($sections as $section) {
            $html .= $this->renderSection($section, $settings);
        }
        
        return $html;
    }
    
    /**
     * Renderizar uma seção individual
     */
    private function renderSection($section, $settings) {
        $type = $section['type'] ?? 'unknown';
        $method = 'render' . str_replace('-', '', ucwords($type, '-'));
        
        if (method_exists($this, $method)) {
            return $this->$method($section, $settings);
        }
        
        // Fallback para seção desconhecida
        return '<!-- Seção não reconhecida: ' . htmlspecialchars($type) . ' -->';
    }
    
    /**
     * Renderizar Banner
     */
    private function renderBanner($section, $settings) {
        $id = $section['id'] ?? 'banner-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? '');
        $subtitle = htmlspecialchars($section['subtitle'] ?? '');
        $buttonText = htmlspecialchars($section['buttonText'] ?? '');
        $buttonUrl = htmlspecialchars($section['buttonUrl'] ?? '#');
        $bgImage = $section['backgroundImage'] ?? '';
        $bgColor = $section['backgroundColor'] ?? '#2563eb';
        $textColor = $section['textColor'] ?? '#ffffff';
        $height = (int)($section['height'] ?? 400);
        $overlay = $section['overlay'] ?? true;
        $overlayOpacity = (int)($section['overlayOpacity'] ?? 40);
        
        $style = "min-height: {$height}px; background-color: {$bgColor};";
        if ($bgImage) {
            $style .= " background-image: url('{$bgImage}'); background-size: cover; background-position: center;";
        }
        
        $overlayHtml = $overlay ? '<div class="pb-banner-overlay" style="opacity: ' . ($overlayOpacity/100) . '"></div>' : '';
        
        return <<<HTML
<section class="pb-section pb-banner" id="{$id}" style="{$style}">
    {$overlayHtml}
    <div class="pb-banner-content" style="color: {$textColor}">
        <h2 class="pb-banner-title">{$title}</h2>
        <p class="pb-banner-subtitle">{$subtitle}</p>
        <a href="{$buttonUrl}" class="pb-btn pb-btn-primary">{$buttonText}</a>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar Grid de Produtos
     */
    private function renderProducts($section, $settings) {
        $this->load->model('catalog/product');
        $this->load->model('tool/image');
        
        $id = $section['id'] ?? 'products-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? 'Produtos');
        $columns = (int)($section['columns'] ?? 4);
        $limit = (int)($section['limit'] ?? 8);
        $source = $section['source'] ?? 'featured';
        $showPrice = $section['showPrice'] ?? true;
        $showButton = $section['showButton'] ?? true;
        
        // Buscar produtos
        $filter = array('start' => 0, 'limit' => $limit);
        
        switch ($source) {
            case 'bestsellers':
                $products = $this->model_catalog_product->getBestSellerProducts($limit);
                break;
            case 'new':
                $filter['sort'] = 'p.date_added';
                $filter['order'] = 'DESC';
                $products = $this->model_catalog_product->getProducts($filter);
                break;
            case 'sale':
                $products = $this->model_catalog_product->getProductSpecials($filter);
                break;
            case 'category':
                $filter['filter_category_id'] = $section['categoryId'] ?? 0;
                $products = $this->model_catalog_product->getProducts($filter);
                break;
            default:
                $products = $this->model_catalog_product->getProducts($filter);
        }
        
        $productsHtml = '';
        foreach ($products as $product) {
            $image = $this->model_tool_image->resize($product['image'] ?? 'placeholder.png', 300, 300);
            $name = htmlspecialchars($product['name']);
            $price = $this->currency->format($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
            $url = $this->url->link('product/product', 'product_id=' . $product['product_id']);
            
            $priceHtml = $showPrice ? '<div class="pb-product-price">' . $price . '</div>' : '';
            $buttonHtml = $showButton ? '<button class="pb-btn pb-btn-sm" onclick="cart.add(' . $product['product_id'] . ')">Comprar</button>' : '';
            
            $productsHtml .= <<<HTML
<div class="pb-product-card">
    <a href="{$url}" class="pb-product-image">
        <img src="{$image}" alt="{$name}" loading="lazy">
    </a>
    <div class="pb-product-info">
        <a href="{$url}" class="pb-product-name">{$name}</a>
        {$priceHtml}
        {$buttonHtml}
    </div>
</div>
HTML;
        }
        
        return <<<HTML
<section class="pb-section pb-products" id="{$id}">
    <div class="pb-container">
        <h3 class="pb-section-title">{$title}</h3>
        <div class="pb-product-grid pb-grid-{$columns}">
            {$productsHtml}
        </div>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar Bloco de Texto
     */
    private function renderText($section, $settings) {
        $id = $section['id'] ?? 'text-' . uniqid();
        $content = $section['content'] ?? '';
        $textAlign = $section['textAlign'] ?? 'left';
        $maxWidth = (int)($section['maxWidth'] ?? 800);
        
        return <<<HTML
<section class="pb-section pb-text" id="{$id}">
    <div class="pb-container">
        <div class="pb-text-content" style="text-align: {$textAlign}; max-width: {$maxWidth}px; margin: 0 auto;">
            {$content}
        </div>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar Categorias
     */
    private function renderCategories($section, $settings) {
        $this->load->model('catalog/category');
        $this->load->model('tool/image');
        
        $id = $section['id'] ?? 'categories-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? 'Categorias');
        $columns = (int)($section['columns'] ?? 6);
        $showIcon = $section['showIcon'] ?? true;
        
        $categories = $this->model_catalog_category->getCategories(array('parent_id' => 0));
        
        $categoriesHtml = '';
        foreach ($categories as $category) {
            $name = htmlspecialchars($category['name']);
            $url = $this->url->link('product/category', 'path=' . $category['category_id']);
            $image = isset($category['image']) ? $this->model_tool_image->resize($category['image'], 100, 100) : '';
            
            $iconHtml = '';
            if ($showIcon && $image) {
                $iconHtml = '<img src="' . $image . '" alt="' . $name . '" class="pb-category-icon">';
            } elseif ($showIcon) {
                $iconHtml = '<div class="pb-category-icon-placeholder"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg></div>';
            }
            
            $categoriesHtml .= <<<HTML
<a href="{$url}" class="pb-category-card">
    {$iconHtml}
    <span class="pb-category-name">{$name}</span>
</a>
HTML;
        }
        
        return <<<HTML
<section class="pb-section pb-categories" id="{$id}">
    <div class="pb-container">
        <h3 class="pb-section-title">{$title}</h3>
        <div class="pb-category-grid pb-grid-{$columns}">
            {$categoriesHtml}
        </div>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar Countdown
     */
    private function renderCountdown($section, $settings) {
        $id = $section['id'] ?? 'countdown-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? 'Oferta por Tempo Limitado!');
        $endDate = $section['endDate'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));
        
        return <<<HTML
<section class="pb-section pb-countdown" id="{$id}">
    <div class="pb-container">
        <h3 class="pb-countdown-title">{$title}</h3>
        <div class="pb-countdown-timer" data-end="{$endDate}">
            <div class="pb-countdown-item">
                <span class="pb-countdown-number" data-days>00</span>
                <span class="pb-countdown-label">Dias</span>
            </div>
            <div class="pb-countdown-item">
                <span class="pb-countdown-number" data-hours>00</span>
                <span class="pb-countdown-label">Horas</span>
            </div>
            <div class="pb-countdown-item">
                <span class="pb-countdown-number" data-minutes>00</span>
                <span class="pb-countdown-label">Min</span>
            </div>
            <div class="pb-countdown-item">
                <span class="pb-countdown-number" data-seconds>00</span>
                <span class="pb-countdown-label">Seg</span>
            </div>
        </div>
    </div>
</section>
<script>
(function() {
    const timer = document.querySelector('#{$id} .pb-countdown-timer');
    const endDate = new Date(timer.dataset.end).getTime();
    
    const update = () => {
        const now = new Date().getTime();
        const diff = endDate - now;
        
        if (diff > 0) {
            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);
            
            timer.querySelector('[data-days]').textContent = String(days).padStart(2, '0');
            timer.querySelector('[data-hours]').textContent = String(hours).padStart(2, '0');
            timer.querySelector('[data-minutes]').textContent = String(minutes).padStart(2, '0');
            timer.querySelector('[data-seconds]').textContent = String(seconds).padStart(2, '0');
        }
    };
    
    update();
    setInterval(update, 1000);
})();
</script>
HTML;
    }
    
    /**
     * Renderizar Newsletter
     */
    private function renderNewsletter($section, $settings) {
        $id = $section['id'] ?? 'newsletter-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? 'Inscreva-se na Newsletter');
        $subtitle = htmlspecialchars($section['subtitle'] ?? 'Receba ofertas exclusivas!');
        $buttonText = htmlspecialchars($section['buttonText'] ?? 'Inscrever');
        $bgColor = $section['backgroundColor'] ?? '#1f2937';
        
        return <<<HTML
<section class="pb-section pb-newsletter" id="{$id}" style="background: {$bgColor}">
    <div class="pb-container">
        <h3 class="pb-newsletter-title">{$title}</h3>
        <p class="pb-newsletter-subtitle">{$subtitle}</p>
        <form class="pb-newsletter-form" onsubmit="return subscribeNewsletter(this)">
            <input type="email" name="email" placeholder="Seu melhor e-mail" required class="pb-newsletter-input">
            <button type="submit" class="pb-btn pb-btn-primary">{$buttonText}</button>
        </form>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar Features/Recursos
     */
    private function renderFeatures($section, $settings) {
        $id = $section['id'] ?? 'features-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? 'Nossos Diferenciais');
        $columns = (int)($section['columns'] ?? 3);
        $items = $section['items'] ?? [];
        
        $itemsHtml = '';
        foreach ($items as $item) {
            $icon = $item['icon'] ?? 'star';
            $itemTitle = htmlspecialchars($item['title'] ?? '');
            $itemText = htmlspecialchars($item['text'] ?? '');
            
            $itemsHtml .= <<<HTML
<div class="pb-feature-card">
    <div class="pb-feature-icon">
        <svg class="lucide-icon" data-icon="{$icon}"></svg>
    </div>
    <h4 class="pb-feature-title">{$itemTitle}</h4>
    <p class="pb-feature-text">{$itemText}</p>
</div>
HTML;
        }
        
        return <<<HTML
<section class="pb-section pb-features" id="{$id}">
    <div class="pb-container">
        <h3 class="pb-section-title">{$title}</h3>
        <div class="pb-features-grid pb-grid-{$columns}">
            {$itemsHtml}
        </div>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar CTA
     */
    private function renderCta($section, $settings) {
        $id = $section['id'] ?? 'cta-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? 'Pronto para começar?');
        $subtitle = htmlspecialchars($section['subtitle'] ?? '');
        $primaryButton = htmlspecialchars($section['primaryButton'] ?? 'Comprar Agora');
        $secondaryButton = htmlspecialchars($section['secondaryButton'] ?? '');
        $bgColor = $section['backgroundColor'] ?? '#2563eb';
        
        $secondaryHtml = $secondaryButton ? '<a href="#" class="pb-btn pb-btn-outline">' . $secondaryButton . '</a>' : '';
        
        return <<<HTML
<section class="pb-section pb-cta" id="{$id}" style="background: {$bgColor}">
    <div class="pb-container">
        <h3 class="pb-cta-title">{$title}</h3>
        <p class="pb-cta-subtitle">{$subtitle}</p>
        <div class="pb-cta-buttons">
            <a href="#" class="pb-btn pb-btn-white">{$primaryButton}</a>
            {$secondaryHtml}
        </div>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar Testimonials
     */
    private function renderTestimonials($section, $settings) {
        $id = $section['id'] ?? 'testimonials-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? 'O que dizem nossos clientes');
        $items = $section['items'] ?? [];
        
        $itemsHtml = '';
        foreach ($items as $item) {
            $text = htmlspecialchars($item['text'] ?? '');
            $author = htmlspecialchars($item['author'] ?? '');
            $rating = (int)($item['rating'] ?? 5);
            
            $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
            
            $itemsHtml .= <<<HTML
<div class="pb-testimonial-card">
    <div class="pb-testimonial-stars">{$stars}</div>
    <p class="pb-testimonial-text">"{$text}"</p>
    <div class="pb-testimonial-author">— {$author}</div>
</div>
HTML;
        }
        
        return <<<HTML
<section class="pb-section pb-testimonials" id="{$id}">
    <div class="pb-container">
        <h3 class="pb-section-title">{$title}</h3>
        <div class="pb-testimonials-grid">
            {$itemsHtml}
        </div>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar Divider
     */
    private function renderDivider($section, $settings) {
        $color = $section['color'] ?? '#e5e7eb';
        $width = (int)($section['width'] ?? 1);
        $margin = (int)($section['margin'] ?? 40);
        
        return <<<HTML
<div class="pb-divider" style="margin: {$margin}px auto;">
    <hr style="border: none; height: {$width}px; background: {$color};">
</div>
HTML;
    }
    
    /**
     * Renderizar Spacer
     */
    private function renderSpacer($section, $settings) {
        $height = (int)($section['height'] ?? 60);
        
        return '<div class="pb-spacer" style="height: ' . $height . 'px;"></div>';
    }
    
    /**
     * Renderizar Video
     */
    private function renderVideo($section, $settings) {
        $id = $section['id'] ?? 'video-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? '');
        $videoUrl = $section['videoUrl'] ?? '';
        $autoplay = $section['autoplay'] ?? false;
        
        // Detectar YouTube ou Vimeo
        $embedUrl = '';
        if (preg_match('/youtube\.com\/watch\?v=([^&]+)/', $videoUrl, $matches)) {
            $embedUrl = 'https://www.youtube.com/embed/' . $matches[1] . ($autoplay ? '?autoplay=1' : '');
        } elseif (preg_match('/youtu\.be\/([^?]+)/', $videoUrl, $matches)) {
            $embedUrl = 'https://www.youtube.com/embed/' . $matches[1] . ($autoplay ? '?autoplay=1' : '');
        } elseif (preg_match('/vimeo\.com\/(\d+)/', $videoUrl, $matches)) {
            $embedUrl = 'https://player.vimeo.com/video/' . $matches[1] . ($autoplay ? '?autoplay=1' : '');
        }
        
        $titleHtml = $title ? '<h3 class="pb-section-title">' . $title . '</h3>' : '';
        
        return <<<HTML
<section class="pb-section pb-video" id="{$id}">
    <div class="pb-container">
        {$titleHtml}
        <div class="pb-video-wrapper">
            <iframe src="{$embedUrl}" frameborder="0" allowfullscreen allow="autoplay"></iframe>
        </div>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar Social
     */
    private function renderSocial($section, $settings) {
        $id = $section['id'] ?? 'social-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? 'Siga-nos');
        $networks = $section['networks'] ?? ['facebook', 'instagram', 'twitter'];
        
        $iconsHtml = '';
        foreach ($networks as $network) {
            $iconsHtml .= '<a href="#" class="pb-social-icon pb-social-' . $network . '" target="_blank"><span class="sr-only">' . ucfirst($network) . '</span></a>';
        }
        
        return <<<HTML
<section class="pb-section pb-social" id="{$id}">
    <div class="pb-container">
        <h3 class="pb-section-title">{$title}</h3>
        <div class="pb-social-icons">
            {$iconsHtml}
        </div>
    </div>
</section>
HTML;
    }
    
    /**
     * Renderizar Gallery
     */
    private function renderGallery($section, $settings) {
        $id = $section['id'] ?? 'gallery-' . uniqid();
        $title = htmlspecialchars($section['title'] ?? 'Galeria');
        $columns = (int)($section['columns'] ?? 3);
        $images = $section['images'] ?? [];
        
        $imagesHtml = '';
        foreach ($images as $image) {
            $src = htmlspecialchars($image['src'] ?? '');
            $alt = htmlspecialchars($image['alt'] ?? '');
            $imagesHtml .= '<div class="pb-gallery-item"><img src="' . $src . '" alt="' . $alt . '" loading="lazy"></div>';
        }
        
        $titleHtml = $title ? '<h3 class="pb-section-title">' . $title . '</h3>' : '';
        
        return <<<HTML
<section class="pb-section pb-gallery" id="{$id}">
    <div class="pb-container">
        {$titleHtml}
        <div class="pb-gallery-grid pb-grid-{$columns}">
            {$imagesHtml}
        </div>
    </div>
</section>
HTML;
    }
    
    /**
     * Gerar CSS customizado baseado nas configurações
     */
    private function generateCustomCSS($settings) {
        $primary = $settings['primaryColor'] ?? '#2563eb';
        $secondary = $settings['secondaryColor'] ?? '#10b981';
        $accent = $settings['accentColor'] ?? '#f59e0b';
        $text = $settings['textColor'] ?? '#1f2937';
        $font = $settings['fontFamily'] ?? "'Plus Jakarta Sans', sans-serif";
        $containerWidth = (int)($settings['containerWidth'] ?? 1400);
        $borderRadius = (int)($settings['borderRadius'] ?? 8);
        
        return <<<CSS
<style>
:root {
    --pb-primary: {$primary};
    --pb-secondary: {$secondary};
    --pb-accent: {$accent};
    --pb-text: {$text};
    --pb-font: {$font};
    --pb-container: {$containerWidth}px;
    --pb-radius: {$borderRadius}px;
}
</style>
CSS;
    }
}
