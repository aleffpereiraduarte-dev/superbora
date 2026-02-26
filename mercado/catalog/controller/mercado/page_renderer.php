<?php
/**
 * Page Builder Renderer - Mercado OneMundo
 * Controller frontend para renderizar páginas criadas no Page Builder
 */

class ControllerMercadoPageRenderer extends Controller {
    
    private $settings = array();
    
    /**
     * Renderizar página por slug
     */
    public function index() {
        $slug = isset($this->request->get['slug']) ? $this->request->get['slug'] : '';
        
        $this->load->model('mercado/page_builder');
        
        // Obter página
        if ($slug) {
            $page = $this->model_mercado_page_builder->getPageBySlug($slug);
        } else {
            $page = $this->model_mercado_page_builder->getHomepage();
        }
        
        if (!$page) {
            $this->response->redirect($this->url->link('error/not_found'));
            return;
        }
        
        // Configurações globais
        $this->settings = $this->model_mercado_page_builder->getSettings();
        
        // Decodificar layout
        $layout = json_decode($page['layout_json'], true);
        $pageSettings = json_decode($page['settings_json'], true);
        
        // Mesclar configurações
        $this->settings = array_merge($this->settings, $pageSettings ?? []);
        
        // SEO
        $this->document->setTitle($page['title']);
        if (!empty($page['description'])) {
            $this->document->setDescription($page['description']);
        }
        
        // CSS do Page Builder
        $this->document->addStyle('catalog/view/css/page-builder.css');
        
        // Renderizar seções
        $sections_html = '';
        if (!empty($layout['sections'])) {
            foreach ($layout['sections'] as $section) {
                $sections_html .= $this->renderSection($section);
            }
        }
        
        // Blocos globais (header/footer)
        $global_blocks = $this->model_mercado_page_builder->getGlobalBlocks();
        
        $data['page'] = $page;
        $data['sections'] = $sections_html;
        $data['settings'] = $this->settings;
        $data['global_blocks'] = $global_blocks;
        
        $data['header'] = $this->load->controller('common/header');
        $data['footer'] = $this->load->controller('common/footer');
        
        $this->response->setOutput($this->load->view('mercado/page_renderer', $data));
    }
    
    /**
     * Renderizar seção individual
     */
    private function renderSection($section) {
        $type = $section['type'] ?? 'unknown';
        $id = $section['id'] ?? uniqid('section_');
        
        // Estilos inline
        $styles = $this->buildSectionStyles($section);
        $classes = $this->buildSectionClasses($section);
        
        // Renderizar conteúdo baseado no tipo
        $content = $this->renderSectionContent($section);
        
        // Animação
        $animation = '';
        if (!empty($section['animation']) && $section['animation'] !== 'none') {
            $animation = ' data-animation="' . htmlspecialchars($section['animation']) . '"';
            $delay = $section['animationDelay'] ?? 0;
            if ($delay) {
                $animation .= ' data-delay="' . (int)$delay . '"';
            }
        }
        
        return sprintf(
            '<section id="%s" class="pb-section pb-section--%s %s" style="%s"%s>%s</section>',
            htmlspecialchars($id),
            htmlspecialchars($type),
            htmlspecialchars($classes),
            htmlspecialchars($styles),
            $animation,
            $content
        );
    }
    
    /**
     * Renderizar conteúdo da seção
     */
    private function renderSectionContent($section) {
        $type = $section['type'] ?? 'unknown';
        
        switch ($type) {
            case 'banner':
                return $this->renderBanner($section);
            case 'products':
                return $this->renderProducts($section);
            case 'categories':
                return $this->renderCategories($section);
            case 'text':
                return $this->renderText($section);
            case 'carousel':
                return $this->renderCarousel($section);
            case 'video':
                return $this->renderVideo($section);
            case 'countdown':
                return $this->renderCountdown($section);
            case 'newsletter':
                return $this->renderNewsletter($section);
            case 'features':
                return $this->renderFeatures($section);
            case 'testimonials':
                return $this->renderTestimonials($section);
            case 'cta':
                return $this->renderCTA($section);
            case 'gallery':
                return $this->renderGallery($section);
            case 'social':
                return $this->renderSocial($section);
            case 'map':
                return $this->renderMap($section);
            case 'divider':
                return $this->renderDivider($section);
            case 'spacer':
                return $this->renderSpacer($section);
            case 'columns':
                return $this->renderColumns($section);
            case 'html':
                return $this->renderHTML($section);
            default:
                return '<!-- Unknown section type: ' . htmlspecialchars($type) . ' -->';
        }
    }
    
    /**
     * Banner
     */
    private function renderBanner($section) {
        $title = $section['title'] ?? '';
        $subtitle = $section['subtitle'] ?? '';
        $buttonText = $section['buttonText'] ?? '';
        $buttonUrl = $section['buttonUrl'] ?? '#';
        $bgImage = $section['backgroundImage'] ?? '';
        $bgColor = $section['backgroundColor'] ?? '#2563eb';
        $textColor = $section['textColor'] ?? '#ffffff';
        $height = $section['height'] ?? 400;
        $overlay = $section['overlay'] ?? true;
        $overlayOpacity = $section['overlayOpacity'] ?? 40;
        $textAlign = $section['textAlign'] ?? 'center';
        
        $bgStyle = $bgImage 
            ? "background-image:url('{$bgImage}');background-size:cover;background-position:center;"
            : "background-color:{$bgColor};";
        
        $overlayHtml = $overlay 
            ? '<div class="pb-banner__overlay" style="opacity:' . ($overlayOpacity/100) . '"></div>'
            : '';
        
        $buttonHtml = $buttonText 
            ? '<a href="' . htmlspecialchars($buttonUrl) . '" class="pb-banner__button">' . htmlspecialchars($buttonText) . '</a>'
            : '';
        
        return <<<HTML
<div class="pb-banner" style="{$bgStyle}min-height:{$height}px;">
    {$overlayHtml}
    <div class="pb-banner__content" style="text-align:{$textAlign};color:{$textColor}">
        <h2 class="pb-banner__title">{$title}</h2>
        <p class="pb-banner__subtitle">{$subtitle}</p>
        {$buttonHtml}
    </div>
</div>
HTML;
    }
    
    /**
     * Grid de Produtos
     */
    private function renderProducts($section) {
        $this->load->model('catalog/product');
        $this->load->model('tool/image');
        
        $title = $section['title'] ?? 'Produtos';
        $columns = $section['columns'] ?? 4;
        $limit = $section['limit'] ?? 8;
        $source = $section['source'] ?? 'featured';
        $showPrice = $section['showPrice'] ?? true;
        $showButton = $section['showButton'] ?? true;
        
        // Filtros baseados na fonte
        $filter = array(
            'start' => 0,
            'limit' => $limit
        );
        
        switch ($source) {
            case 'bestsellers':
                $filter['sort'] = 'p.viewed';
                $filter['order'] = 'DESC';
                break;
            case 'new':
                $filter['sort'] = 'p.date_added';
                $filter['order'] = 'DESC';
                break;
            case 'sale':
                $filter['filter_special'] = true;
                break;
            case 'category':
                if (!empty($section['categoryId'])) {
                    $filter['filter_category_id'] = (int)$section['categoryId'];
                }
                break;
        }
        
        $products = $this->model_catalog_product->getProducts($filter);
        
        $productsHtml = '';
        foreach ($products as $product) {
            $image = $this->model_tool_image->resize($product['image'] ?? 'placeholder.png', 300, 300);
            $price = $this->currency->format($product['price'], $this->config->get('config_currency'));
            $url = $this->url->link('product/product', 'product_id=' . $product['product_id']);
            
            $priceHtml = $showPrice ? '<div class="pb-product__price">' . $price . '</div>' : '';
            $buttonHtml = $showButton ? '<a href="' . $url . '" class="pb-product__button">Ver Produto</a>' : '';
            
            $productsHtml .= <<<HTML
<div class="pb-product">
    <a href="{$url}" class="pb-product__image">
        <img src="{$image}" alt="{$product['name']}" loading="lazy">
    </a>
    <div class="pb-product__info">
        <h4 class="pb-product__name"><a href="{$url}">{$product['name']}</a></h4>
        {$priceHtml}
        {$buttonHtml}
    </div>
</div>
HTML;
        }
        
        $titleHtml = $title ? '<h3 class="pb-section__title">' . htmlspecialchars($title) . '</h3>' : '';
        
        return <<<HTML
<div class="pb-container">
    {$titleHtml}
    <div class="pb-products-grid" style="grid-template-columns:repeat({$columns}, 1fr)">
        {$productsHtml}
    </div>
</div>
HTML;
    }
    
    /**
     * Grid de Categorias
     */
    private function renderCategories($section) {
        $this->load->model('catalog/category');
        $this->load->model('tool/image');
        
        $title = $section['title'] ?? 'Categorias';
        $columns = $section['columns'] ?? 6;
        $showIcon = $section['showIcon'] ?? true;
        $style = $section['style'] ?? 'cards';
        
        $categories = $this->model_catalog_category->getCategories(array('parent_id' => 0));
        
        $categoriesHtml = '';
        foreach ($categories as $category) {
            $image = !empty($category['image']) 
                ? $this->model_tool_image->resize($category['image'], 150, 150)
                : '';
            $url = $this->url->link('product/category', 'path=' . $category['category_id']);
            
            $imageHtml = $image && $showIcon
                ? '<img src="' . $image . '" alt="' . htmlspecialchars($category['name']) . '" class="pb-category__icon">'
                : '<i class="pb-icon pb-icon--folder"></i>';
            
            $categoriesHtml .= <<<HTML
<a href="{$url}" class="pb-category pb-category--{$style}">
    {$imageHtml}
    <span class="pb-category__name">{$category['name']}</span>
</a>
HTML;
        }
        
        $titleHtml = $title ? '<h3 class="pb-section__title">' . htmlspecialchars($title) . '</h3>' : '';
        
        return <<<HTML
<div class="pb-container">
    {$titleHtml}
    <div class="pb-categories-grid" style="grid-template-columns:repeat({$columns}, 1fr)">
        {$categoriesHtml}
    </div>
</div>
HTML;
    }
    
    /**
     * Bloco de Texto
     */
    private function renderText($section) {
        $content = $section['content'] ?? '';
        $textAlign = $section['textAlign'] ?? 'left';
        $maxWidth = $section['maxWidth'] ?? 800;
        
        return <<<HTML
<div class="pb-container">
    <div class="pb-text-block" style="text-align:{$textAlign};max-width:{$maxWidth}px;margin:0 auto">
        {$content}
    </div>
</div>
HTML;
    }
    
    /**
     * Carrossel
     */
    private function renderCarousel($section) {
        $slides = $section['slides'] ?? [];
        $autoplay = $section['autoplay'] ?? true;
        $interval = $section['interval'] ?? 5000;
        $arrows = $section['arrows'] ?? true;
        $dots = $section['dots'] ?? true;
        
        $slidesHtml = '';
        foreach ($slides as $slide) {
            $image = $slide['image'] ?? '';
            $title = $slide['title'] ?? '';
            $link = $slide['link'] ?? '#';
            
            $slidesHtml .= <<<HTML
<div class="pb-carousel__slide">
    <a href="{$link}">
        <img src="{$image}" alt="{$title}" loading="lazy">
    </a>
</div>
HTML;
        }
        
        $arrowsHtml = $arrows ? '
            <button class="pb-carousel__arrow pb-carousel__arrow--prev"><i class="pb-icon pb-icon--chevron-left"></i></button>
            <button class="pb-carousel__arrow pb-carousel__arrow--next"><i class="pb-icon pb-icon--chevron-right"></i></button>
        ' : '';
        
        $dotsHtml = $dots ? '<div class="pb-carousel__dots"></div>' : '';
        
        $autoplayAttr = $autoplay ? 'data-autoplay="true" data-interval="' . (int)$interval . '"' : '';
        
        return <<<HTML
<div class="pb-carousel" {$autoplayAttr}>
    <div class="pb-carousel__track">
        {$slidesHtml}
    </div>
    {$arrowsHtml}
    {$dotsHtml}
</div>
HTML;
    }
    
    /**
     * Vídeo
     */
    private function renderVideo($section) {
        $title = $section['title'] ?? '';
        $videoUrl = $section['videoUrl'] ?? '';
        $aspectRatio = $section['aspectRatio'] ?? '16:9';
        $autoplay = $section['autoplay'] ?? false;
        
        $titleHtml = $title ? '<h3 class="pb-section__title">' . htmlspecialchars($title) . '</h3>' : '';
        
        // Detectar tipo de vídeo
        $embedUrl = $this->getVideoEmbedUrl($videoUrl, $autoplay);
        
        $paddingMap = [
            '16:9' => '56.25%',
            '4:3' => '75%',
            '21:9' => '42.86%',
            '1:1' => '100%'
        ];
        $padding = $paddingMap[$aspectRatio] ?? '56.25%';
        
        return <<<HTML
<div class="pb-container">
    {$titleHtml}
    <div class="pb-video" style="padding-bottom:{$padding}">
        <iframe src="{$embedUrl}" frameborder="0" allowfullscreen allow="autoplay"></iframe>
    </div>
</div>
HTML;
    }
    
    /**
     * Countdown
     */
    private function renderCountdown($section) {
        $title = $section['title'] ?? 'Oferta por Tempo Limitado!';
        $endDate = $section['endDate'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));
        $textColor = $section['textColor'] ?? '#ffffff';
        
        return <<<HTML
<div class="pb-countdown" data-end="{$endDate}" style="color:{$textColor}">
    <h3 class="pb-countdown__title">{$title}</h3>
    <div class="pb-countdown__timer">
        <div class="pb-countdown__item">
            <span class="pb-countdown__number" data-days>00</span>
            <span class="pb-countdown__label">Dias</span>
        </div>
        <div class="pb-countdown__item">
            <span class="pb-countdown__number" data-hours>00</span>
            <span class="pb-countdown__label">Horas</span>
        </div>
        <div class="pb-countdown__item">
            <span class="pb-countdown__number" data-minutes>00</span>
            <span class="pb-countdown__label">Min</span>
        </div>
        <div class="pb-countdown__item">
            <span class="pb-countdown__number" data-seconds>00</span>
            <span class="pb-countdown__label">Seg</span>
        </div>
    </div>
</div>
HTML;
    }
    
    /**
     * Newsletter
     */
    private function renderNewsletter($section) {
        $title = $section['title'] ?? 'Inscreva-se na Newsletter';
        $subtitle = $section['subtitle'] ?? 'Receba ofertas exclusivas!';
        $buttonText = $section['buttonText'] ?? 'Inscrever';
        $bgColor = $section['backgroundColor'] ?? '#1f2937';
        
        return <<<HTML
<div class="pb-newsletter" style="background:{$bgColor}">
    <div class="pb-container">
        <h3 class="pb-newsletter__title">{$title}</h3>
        <p class="pb-newsletter__subtitle">{$subtitle}</p>
        <form class="pb-newsletter__form" method="post" action="index.php?route=mercado/newsletter/subscribe">
            <input type="email" name="email" placeholder="Seu melhor e-mail" required>
            <button type="submit">{$buttonText}</button>
        </form>
    </div>
</div>
HTML;
    }
    
    /**
     * Features/Recursos
     */
    private function renderFeatures($section) {
        $title = $section['title'] ?? '';
        $columns = $section['columns'] ?? 3;
        $items = $section['items'] ?? [];
        
        $itemsHtml = '';
        foreach ($items as $item) {
            $icon = $item['icon'] ?? 'star';
            $itemTitle = $item['title'] ?? '';
            $text = $item['text'] ?? '';
            
            $itemsHtml .= <<<HTML
<div class="pb-feature">
    <div class="pb-feature__icon">
        <i class="pb-icon pb-icon--{$icon}"></i>
    </div>
    <h4 class="pb-feature__title">{$itemTitle}</h4>
    <p class="pb-feature__text">{$text}</p>
</div>
HTML;
        }
        
        $titleHtml = $title ? '<h3 class="pb-section__title">' . htmlspecialchars($title) . '</h3>' : '';
        
        return <<<HTML
<div class="pb-container">
    {$titleHtml}
    <div class="pb-features-grid" style="grid-template-columns:repeat({$columns}, 1fr)">
        {$itemsHtml}
    </div>
</div>
HTML;
    }
    
    /**
     * Depoimentos
     */
    private function renderTestimonials($section) {
        $title = $section['title'] ?? '';
        $items = $section['items'] ?? [];
        
        $itemsHtml = '';
        foreach ($items as $item) {
            $text = $item['text'] ?? '';
            $author = $item['author'] ?? '';
            $rating = $item['rating'] ?? 5;
            
            $starsHtml = str_repeat('<i class="pb-icon pb-icon--star"></i>', $rating);
            
            $itemsHtml .= <<<HTML
<div class="pb-testimonial">
    <div class="pb-testimonial__stars">{$starsHtml}</div>
    <p class="pb-testimonial__text">"{$text}"</p>
    <div class="pb-testimonial__author">— {$author}</div>
</div>
HTML;
        }
        
        $titleHtml = $title ? '<h3 class="pb-section__title">' . htmlspecialchars($title) . '</h3>' : '';
        
        return <<<HTML
<div class="pb-container">
    {$titleHtml}
    <div class="pb-testimonials">
        {$itemsHtml}
    </div>
</div>
HTML;
    }
    
    /**
     * CTA (Call to Action)
     */
    private function renderCTA($section) {
        $title = $section['title'] ?? '';
        $subtitle = $section['subtitle'] ?? '';
        $primaryButton = $section['primaryButton'] ?? '';
        $primaryUrl = $section['primaryUrl'] ?? '#';
        $secondaryButton = $section['secondaryButton'] ?? '';
        $secondaryUrl = $section['secondaryUrl'] ?? '#';
        
        $primaryHtml = $primaryButton 
            ? '<a href="' . htmlspecialchars($primaryUrl) . '" class="pb-cta__button pb-cta__button--primary">' . htmlspecialchars($primaryButton) . '</a>'
            : '';
        $secondaryHtml = $secondaryButton 
            ? '<a href="' . htmlspecialchars($secondaryUrl) . '" class="pb-cta__button pb-cta__button--secondary">' . htmlspecialchars($secondaryButton) . '</a>'
            : '';
        
        return <<<HTML
<div class="pb-cta">
    <div class="pb-container">
        <h3 class="pb-cta__title">{$title}</h3>
        <p class="pb-cta__subtitle">{$subtitle}</p>
        <div class="pb-cta__buttons">
            {$primaryHtml}
            {$secondaryHtml}
        </div>
    </div>
</div>
HTML;
    }
    
    /**
     * Galeria
     */
    private function renderGallery($section) {
        $title = $section['title'] ?? '';
        $columns = $section['columns'] ?? 3;
        $images = $section['images'] ?? [];
        
        $imagesHtml = '';
        foreach ($images as $image) {
            $src = $image['src'] ?? $image;
            $alt = $image['alt'] ?? '';
            
            $imagesHtml .= <<<HTML
<div class="pb-gallery__item">
    <img src="{$src}" alt="{$alt}" loading="lazy">
</div>
HTML;
        }
        
        $titleHtml = $title ? '<h3 class="pb-section__title">' . htmlspecialchars($title) . '</h3>' : '';
        
        return <<<HTML
<div class="pb-container">
    {$titleHtml}
    <div class="pb-gallery" style="grid-template-columns:repeat({$columns}, 1fr)">
        {$imagesHtml}
    </div>
</div>
HTML;
    }
    
    /**
     * Redes Sociais
     */
    private function renderSocial($section) {
        $title = $section['title'] ?? '';
        $networks = $section['networks'] ?? ['facebook', 'instagram', 'twitter'];
        
        $iconsHtml = '';
        foreach ($networks as $network) {
            $url = $section[$network . '_url'] ?? '#';
            $iconsHtml .= '<a href="' . htmlspecialchars($url) . '" class="pb-social__icon" target="_blank"><i class="pb-icon pb-icon--' . $network . '"></i></a>';
        }
        
        $titleHtml = $title ? '<h3 class="pb-section__title">' . htmlspecialchars($title) . '</h3>' : '';
        
        return <<<HTML
<div class="pb-container pb-social-wrapper">
    {$titleHtml}
    <div class="pb-social">
        {$iconsHtml}
    </div>
</div>
HTML;
    }
    
    /**
     * Mapa
     */
    private function renderMap($section) {
        $title = $section['title'] ?? '';
        $address = $section['address'] ?? '';
        $lat = $section['lat'] ?? -23.5505;
        $lng = $section['lng'] ?? -46.6333;
        $zoom = $section['zoom'] ?? 15;
        $height = $section['height'] ?? 400;
        
        $titleHtml = $title ? '<h3 class="pb-section__title">' . htmlspecialchars($title) . '</h3>' : '';
        
        return <<<HTML
<div class="pb-container">
    {$titleHtml}
    <div class="pb-map" style="height:{$height}px" data-lat="{$lat}" data-lng="{$lng}" data-zoom="{$zoom}">
        <iframe 
            src="https://www.google.com/maps/embed?pb=!1m14!1m12!1m3!1d3000!2d{$lng}!3d{$lat}!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sen!2sbr!4v1600000000000!5m2!1sen!2sbr"
            width="100%" 
            height="100%" 
            style="border:0" 
            allowfullscreen="" 
            loading="lazy">
        </iframe>
    </div>
</div>
HTML;
    }
    
    /**
     * Divisor
     */
    private function renderDivider($section) {
        $style = $section['style'] ?? 'line';
        $color = $section['color'] ?? '#e5e7eb';
        $width = $section['width'] ?? 1;
        $margin = $section['margin'] ?? 40;
        
        return <<<HTML
<div class="pb-container">
    <hr class="pb-divider pb-divider--{$style}" style="border-color:{$color};border-width:{$width}px;margin:{$margin}px 0">
</div>
HTML;
    }
    
    /**
     * Espaçador
     */
    private function renderSpacer($section) {
        $height = $section['height'] ?? 60;
        return '<div class="pb-spacer" style="height:' . (int)$height . 'px"></div>';
    }
    
    /**
     * Colunas
     */
    private function renderColumns($section) {
        $layout = $section['layout'] ?? '1/2-1/2';
        $gap = $section['gap'] ?? 20;
        $content = $section['content'] ?? [];
        
        // Converter layout para grid
        $layouts = [
            '1/2-1/2' => '1fr 1fr',
            '1/3-2/3' => '1fr 2fr',
            '2/3-1/3' => '2fr 1fr',
            '1/3-1/3-1/3' => '1fr 1fr 1fr',
            '1/4-1/4-1/4-1/4' => '1fr 1fr 1fr 1fr',
            '1/4-3/4' => '1fr 3fr',
            '3/4-1/4' => '3fr 1fr'
        ];
        
        $gridTemplate = $layouts[$layout] ?? '1fr 1fr';
        
        $columnsHtml = '';
        foreach ($content as $column) {
            $columnContent = isset($column['sections']) 
                ? implode('', array_map([$this, 'renderSection'], $column['sections']))
                : ($column['html'] ?? '');
            
            $columnsHtml .= '<div class="pb-column">' . $columnContent . '</div>';
        }
        
        return <<<HTML
<div class="pb-container">
    <div class="pb-columns" style="grid-template-columns:{$gridTemplate};gap:{$gap}px">
        {$columnsHtml}
    </div>
</div>
HTML;
    }
    
    /**
     * HTML Customizado
     */
    private function renderHTML($section) {
        $content = $section['content'] ?? '';
        // Sanitizar? Por enquanto confia no admin
        return '<div class="pb-container pb-html">' . $content . '</div>';
    }
    
    /**
     * Construir estilos da seção
     */
    private function buildSectionStyles($section) {
        $styles = [];
        
        if (!empty($section['backgroundColor'])) {
            $styles[] = 'background-color:' . $section['backgroundColor'];
        }
        
        if (!empty($section['backgroundImage'])) {
            $styles[] = 'background-image:url(' . $section['backgroundImage'] . ')';
            $styles[] = 'background-size:cover';
            $styles[] = 'background-position:center';
        }
        
        if (!empty($section['backgroundGradient'])) {
            $styles[] = 'background:' . $section['backgroundGradient'];
        }
        
        // Padding
        $padding = [];
        foreach (['Top', 'Right', 'Bottom', 'Left'] as $side) {
            $key = 'padding' . $side;
            if (isset($section[$key])) {
                $padding[] = $section[$key];
            }
        }
        if (count($padding) === 4) {
            $styles[] = 'padding:' . implode(' ', $padding);
        } elseif (!empty($section['padding'])) {
            $styles[] = 'padding:' . $section['padding'];
        }
        
        // Margin
        $margin = [];
        foreach (['Top', 'Right', 'Bottom', 'Left'] as $side) {
            $key = 'margin' . $side;
            if (isset($section[$key])) {
                $margin[] = $section[$key];
            }
        }
        if (count($margin) === 4) {
            $styles[] = 'margin:' . implode(' ', $margin);
        }
        
        if (!empty($section['borderRadius'])) {
            $styles[] = 'border-radius:' . $section['borderRadius'];
        }
        
        if (!empty($section['minHeight'])) {
            $styles[] = 'min-height:' . $section['minHeight'];
        }
        
        return implode(';', $styles);
    }
    
    /**
     * Construir classes da seção
     */
    private function buildSectionClasses($section) {
        $classes = [];
        
        if (!empty($section['customClass'])) {
            $classes[] = $section['customClass'];
        }
        
        // Visibilidade responsiva
        if (isset($section['hideOnDesktop']) && $section['hideOnDesktop']) {
            $classes[] = 'pb-hide-desktop';
        }
        if (isset($section['hideOnTablet']) && $section['hideOnTablet']) {
            $classes[] = 'pb-hide-tablet';
        }
        if (isset($section['hideOnMobile']) && $section['hideOnMobile']) {
            $classes[] = 'pb-hide-mobile';
        }
        
        // Full width
        if (!empty($section['fullWidth'])) {
            $classes[] = 'pb-full-width';
        }
        
        return implode(' ', $classes);
    }
    
    /**
     * Obter URL de embed para vídeo
     */
    private function getVideoEmbedUrl($url, $autoplay = false) {
        $autoplayParam = $autoplay ? '1' : '0';
        
        // YouTube
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return 'https://www.youtube.com/embed/' . $matches[1] . '?autoplay=' . $autoplayParam;
        }
        
        // Vimeo
        if (preg_match('/vimeo\.com\/(\d+)/', $url, $matches)) {
            return 'https://player.vimeo.com/video/' . $matches[1] . '?autoplay=' . $autoplayParam;
        }
        
        return $url;
    }
    
    /**
     * Preview para o editor
     */
    public function preview() {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (!$data) {
            echo 'Dados inválidos';
            return;
        }
        
        $this->settings = $data['settings'] ?? [];
        
        $html = '';
        if (!empty($data['sections'])) {
            foreach ($data['sections'] as $section) {
                $html .= $this->renderSection($section);
            }
        }
        
        // Retornar HTML completo para preview
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview</title>
    <link href="catalog/view/css/page-builder.css" rel="stylesheet">
</head>
<body>
    ' . $html . '
    <script src="catalog/view/javascript/page-builder.js"></script>
</body>
</html>';
    }
}
