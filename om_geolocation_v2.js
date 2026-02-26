/**
 * OneMundo Geolocation v2.0
 *
 * Sistema unificado de geolocalização:
 * - Geolocalização automática HTML5 ao entrar
 * - Pega CEP do cadastro quando usuário logar
 * - Atualiza produtos no index quando CEP mudar
 * - Sem redirecionamentos - tudo no mesmo lugar
 */
(function() {
    'use strict';

    // Estado global
    var OM_GEO = {
        cep: null,
        city: null,
        state: null,
        source: null,
        customerLogged: false,
        mercadoDisponivel: false,
        parceiros: 0,
        initialized: false
    };

    // Configuracao
    var CONFIG = {
        apiBase: window.location.origin,
        autoGeolocate: true,          // Tentar geolocalização HTML5 automática
        showMercadoOnIndex: true,     // Mostrar produtos do mercado no index
        geolocateTimeout: 10000,      // Timeout para geolocalização (10s)
        cacheTime: 30 * 24 * 60 * 60 * 1000  // 30 dias em ms
    };

    /**
     * Inicialização principal
     */
    function init() {
        if (OM_GEO.initialized) return;
        OM_GEO.initialized = true;

        console.log('[GEO v2] Inicializando...');

        // 1. Carregar dados salvos
        loadSavedData();

        // 2. Buscar dados do servidor (inclui CEP do cadastro se logado)
        fetchServerData().then(function() {
            // 3. Se não tem CEP e auto-geo está ativo, tentar HTML5
            if (!OM_GEO.cep && CONFIG.autoGeolocate) {
                tryHtml5Geolocation();
            }

            // 4. Atualizar interface
            updateDisplay();

            // 5. Se tem CEP e estamos no index, carregar produtos do mercado
            if (OM_GEO.cep && isIndexPage() && OM_GEO.mercadoDisponivel) {
                loadMercadoProducts();
            }
        });

        // Listeners
        setupEventListeners();
    }

    /**
     * Carrega dados salvos do localStorage/cookie
     */
    function loadSavedData() {
        try {
            var saved = localStorage.getItem('onemundo_geolocation');
            if (saved) {
                var data = JSON.parse(saved);
                if (data.cep && data.timestamp && (Date.now() - data.timestamp) < CONFIG.cacheTime) {
                    OM_GEO.cep = data.cep;
                    OM_GEO.city = data.city;
                    OM_GEO.state = data.state;
                    OM_GEO.source = 'cache';
                    console.log('[GEO v2] Carregado do cache:', OM_GEO.cep);
                }
            }
        } catch(e) {
            console.error('[GEO v2] Erro ao carregar cache:', e);
        }
    }

    /**
     * Busca dados do servidor (CEP do usuário logado, etc)
     */
    function fetchServerData() {
        return fetch(CONFIG.apiBase + '/api/geolocation/init.php', {
            credentials: 'include'
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            console.log('[GEO v2] Dados do servidor:', data);

            if (data.success) {
                OM_GEO.customerLogged = data.customer_logged;
                OM_GEO.mercadoDisponivel = data.mercado_disponivel;
                OM_GEO.parceiros = data.parceiros;

                // Se servidor tem CEP mais prioritário (do cadastro)
                if (data.cep && data.source === 'customer_address') {
                    OM_GEO.cep = data.cep;
                    OM_GEO.city = data.city;
                    OM_GEO.state = data.state;
                    OM_GEO.source = 'customer';
                    saveData();
                    console.log('[GEO v2] CEP do cadastro:', OM_GEO.cep);
                }
                // Se não temos CEP local, usar do servidor
                else if (!OM_GEO.cep && data.cep) {
                    OM_GEO.cep = data.cep;
                    OM_GEO.city = data.city;
                    OM_GEO.state = data.state;
                    OM_GEO.source = data.source;
                    saveData();
                }
            }
        })
        .catch(function(err) {
            console.error('[GEO v2] Erro ao buscar dados do servidor:', err);
        });
    }

    /**
     * Tenta geolocalização HTML5
     */
    function tryHtml5Geolocation() {
        if (!navigator.geolocation) {
            console.log('[GEO v2] Geolocalização não suportada');
            return;
        }

        console.log('[GEO v2] Tentando geolocalização HTML5...');

        navigator.geolocation.getCurrentPosition(
            function(position) {
                console.log('[GEO v2] Posição obtida:', position.coords);
                reverseGeocode(position.coords.latitude, position.coords.longitude);
            },
            function(error) {
                console.log('[GEO v2] Geolocalização negada ou erro:', error.message);
            },
            {
                enableHighAccuracy: false,
                timeout: CONFIG.geolocateTimeout,
                maximumAge: 3600000 // 1 hora
            }
        );
    }

    /**
     * Converte coordenadas em CEP usando Nominatim (OpenStreetMap)
     */
    function reverseGeocode(lat, lng) {
        fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&zoom=18&addressdetails=1', {
            headers: { 'Accept-Language': 'pt-BR' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.address) {
                var postcode = data.address.postcode;
                var city = data.address.city || data.address.town || data.address.municipality;
                var state = data.address.state;

                if (postcode) {
                    var cep = postcode.replace(/\D/g, '');
                    if (cep.length === 8) {
                        OM_GEO.cep = cep;
                        OM_GEO.city = city;
                        OM_GEO.state = state;
                        OM_GEO.source = 'html5';
                        saveData();
                        updateDisplay();

                        // Verificar mercado
                        checkMercadoDisponivel();

                        console.log('[GEO v2] CEP via HTML5:', cep, city);
                    }
                }
            }
        })
        .catch(function(err) {
            console.error('[GEO v2] Erro no reverse geocode:', err);
        });
    }

    /**
     * Verifica se mercado está disponível para o CEP
     */
    function checkMercadoDisponivel() {
        if (!OM_GEO.cep) return;

        fetch(CONFIG.apiBase + '/api/home/mercado.php?cep=' + OM_GEO.cep)
        .then(function(res) { return res.json(); })
        .then(function(data) {
            OM_GEO.mercadoDisponivel = data.disponivel;
            OM_GEO.parceiros = data.parceiros || 0;

            if (data.disponivel && isIndexPage()) {
                loadMercadoProducts();
            }
        })
        .catch(function(err) {
            console.error('[GEO v2] Erro ao verificar mercado:', err);
        });
    }

    /**
     * Carrega produtos do mercado no index
     */
    function loadMercadoProducts() {
        if (!OM_GEO.cep || !CONFIG.showMercadoOnIndex) return;

        console.log('[GEO v2] Carregando produtos do mercado para CEP:', OM_GEO.cep);

        fetch(CONFIG.apiBase + '/api/mercado/produtos-por-cep.php?cep=' + OM_GEO.cep + '&limit=12')
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success && data.disponivel && data.produtos && data.produtos.length > 0) {
                injectMercadoSection(data);
            }
        })
        .catch(function(err) {
            console.error('[GEO v2] Erro ao carregar produtos do mercado:', err);
        });
    }

    /**
     * Injeta seção do mercado no index
     */
    function injectMercadoSection(data) {
        // Verificar se já existe
        if (document.getElementById('om-mercado-section')) {
            updateMercadoSection(data);
            return;
        }

        var section = document.createElement('div');
        section.id = 'om-mercado-section';
        section.className = 'om-mercado-section';
        section.innerHTML = buildMercadoHTML(data);

        // Inserir após o primeiro módulo (slider) ou no início do conteúdo
        var target = document.querySelector('.main-content') ||
                     document.querySelector('#content') ||
                     document.querySelector('main');

        if (target) {
            var firstModule = target.querySelector('.module-rows, .swiper-container, .main-slider');
            if (firstModule && firstModule.nextSibling) {
                target.insertBefore(section, firstModule.nextSibling);
            } else {
                target.insertBefore(section, target.firstChild);
            }
        }

        console.log('[GEO v2] Seção do mercado injetada');
    }

    /**
     * Atualiza seção do mercado existente
     */
    function updateMercadoSection(data) {
        var section = document.getElementById('om-mercado-section');
        if (section) {
            section.innerHTML = buildMercadoHTML(data);
        }
    }

    /**
     * Constrói HTML da seção do mercado
     */
    function buildMercadoHTML(data) {
        var html = '<div class="om-mercado-container">';
        html += '<div class="om-mercado-header">';
        html += '<div class="om-mercado-title">';
        html += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>';
        html += '<span>Mercado em ' + (data.cidade || OM_GEO.city || 'sua região') + '</span>';
        html += '</div>';
        html += '<span class="om-mercado-badge">' + data.parceiros + ' lojas disponíveis</span>';
        html += '</div>';

        html += '<div class="om-mercado-products">';
        data.produtos.forEach(function(produto) {
            html += '<a href="' + produto.url + '" class="om-mercado-product">';
            html += '<div class="om-mercado-product-img">';
            html += '<img src="' + produto.image + '" alt="' + produto.name + '" loading="lazy">';
            if (produto.discount > 0) {
                html += '<span class="om-mercado-discount">-' + produto.discount + '%</span>';
            }
            html += '</div>';
            html += '<div class="om-mercado-product-info">';
            html += '<h4>' + produto.name + '</h4>';
            html += '<div class="om-mercado-product-price">';
            if (produto.original_price) {
                html += '<span class="om-mercado-old-price">' + produto.original_price + '</span>';
            }
            html += '<span class="om-mercado-current-price">' + produto.price_formatted + '</span>';
            html += '</div>';
            html += '<div class="om-mercado-seller">';
            html += '<span>' + produto.seller.name + '</span>';
            html += '<span class="om-mercado-delivery">' + produto.seller.delivery_time + '</span>';
            html += '</div>';
            html += '</div>';
            html += '</a>';
        });
        html += '</div>';

        html += '</div>';

        return html;
    }

    /**
     * Verifica se estamos na página index
     */
    function isIndexPage() {
        var path = window.location.pathname;
        var search = window.location.search;

        return path === '/' ||
               path === '/index.php' ||
               (path.indexOf('/index.php') !== -1 && search.indexOf('route=common/home') !== -1) ||
               (search === '' && path === '/');
    }

    /**
     * Salva dados no localStorage e cookie
     */
    function saveData() {
        var data = {
            cep: OM_GEO.cep,
            city: OM_GEO.city,
            state: OM_GEO.state,
            source: OM_GEO.source,
            timestamp: Date.now()
        };

        try {
            localStorage.setItem('onemundo_geolocation', JSON.stringify(data));
        } catch(e) {}

        // Cookie
        try {
            var expires = new Date();
            expires.setTime(expires.getTime() + CONFIG.cacheTime);
            document.cookie = 'onemundo_cep=' + encodeURIComponent(JSON.stringify(data)) + ';expires=' + expires.toUTCString() + ';path=/';
        } catch(e) {}
    }

    /**
     * Atualiza display do CEP no header
     */
    function updateDisplay() {
        var valueEl = document.getElementById('om-geo-value');
        var mobileValueEl = document.getElementById('om-geo-mobile-value');
        var currentEl = document.getElementById('om-geo-current');
        var currentValueEl = document.getElementById('om-geo-current-value');

        if (OM_GEO.cep && OM_GEO.city) {
            var text = OM_GEO.city + ' ' + formatCep(OM_GEO.cep);
            if (valueEl) valueEl.textContent = text;
            if (mobileValueEl) mobileValueEl.textContent = text;
            if (currentEl) currentEl.style.display = 'block';
            if (currentValueEl) currentValueEl.textContent = formatCep(OM_GEO.cep) + ' - ' + OM_GEO.city + '/' + OM_GEO.state;
        } else if (OM_GEO.cep) {
            var text = formatCep(OM_GEO.cep);
            if (valueEl) valueEl.textContent = text;
            if (mobileValueEl) mobileValueEl.textContent = text;
        }

        // Disparar evento
        dispatchCepChanged();
    }

    /**
     * Formata CEP
     */
    function formatCep(cep) {
        var clean = String(cep).replace(/\D/g, '');
        if (clean.length === 8) {
            return clean.substring(0, 5) + '-' + clean.substring(5);
        }
        return cep;
    }

    /**
     * Dispara evento de CEP alterado
     */
    function dispatchCepChanged() {
        var event = new CustomEvent('cepChanged', {
            detail: {
                cep: OM_GEO.cep,
                city: OM_GEO.city,
                state: OM_GEO.state,
                source: OM_GEO.source,
                mercadoDisponivel: OM_GEO.mercadoDisponivel
            }
        });
        document.dispatchEvent(event);
    }

    /**
     * Configura event listeners
     */
    function setupEventListeners() {
        // Quando CEP for submetido manualmente
        document.addEventListener('cepChanged', function(e) {
            if (e.detail && e.detail.cep && e.detail.cep !== OM_GEO.cep) {
                OM_GEO.cep = e.detail.cep;
                OM_GEO.city = e.detail.city;
                OM_GEO.state = e.detail.state;
                saveData();
                checkMercadoDisponivel();
            }
        });

        // Quando página carregar
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    }

    /**
     * API Global
     */
    window.OneMundoGeoV2 = {
        getState: function() { return OM_GEO; },
        setCep: function(cep, city, state) {
            OM_GEO.cep = cep;
            OM_GEO.city = city || null;
            OM_GEO.state = state || null;
            OM_GEO.source = 'manual';
            saveData();
            updateDisplay();
            checkMercadoDisponivel();
        },
        refresh: function() {
            fetchServerData().then(updateDisplay);
        },
        loadMercado: loadMercadoProducts
    };

    // Iniciar
    setupEventListeners();

})();
