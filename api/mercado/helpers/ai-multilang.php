<?php
/**
 * AI Multi-Language Detection & Response
 *
 * Heuristic language detection and multi-language prompt/fallback support.
 * Detects Portuguese, English, and Spanish from user input without API calls.
 *
 * Used by: webhooks/twilio-voice-ai.php, whatsapp-ai.php, ai-retry-handler.php
 * Depends on: nothing (standalone helper)
 *
 * Design principles:
 *   - No API calls — pure word-frequency heuristic
 *   - Portuguese is the default (most customers)
 *   - Never throw exceptions — always return a valid language code
 *   - error_log() with [ai-multilang] prefix
 */


// =============================================================================
// 1. Language Detection
// =============================================================================

/**
 * Detect the language of a text string.
 *
 * Uses word-frequency heuristic against known common words in each language.
 * Portuguese is the default when no strong signal is detected.
 *
 * @param string $text User input text
 * @return string Language code: 'pt', 'en', or 'es'
 */
function detectLanguage(string $text): string {
    try {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        if ($lower === '') {
            return 'pt';
        }

        // Normalize: remove punctuation for word matching
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower);
        $normalized = preg_replace('/\s+/', ' ', trim($normalized));
        $words = explode(' ', $normalized);
        $totalWords = count($words);

        if ($totalWords === 0) {
            return 'pt';
        }

        // English indicator words (common in conversation context)
        $englishWords = [
            'the', 'is', 'are', 'want', 'please', 'hello', 'order', 'would',
            'like', 'need', 'menu', 'delivery', 'thank', 'thanks', 'hi',
            'yes', 'no', 'can', 'could', 'how', 'what', 'where', 'when',
            'my', 'your', 'this', 'that', 'with', 'from', 'have', 'has',
            'not', 'but', 'and', 'for', 'you', 'do', 'does', 'will',
            'item', 'items', 'add', 'remove', 'cart', 'pay', 'payment',
            'address', 'help', 'store', 'price', 'total', 'much',
        ];

        // Spanish indicator words
        $spanishWords = [
            'quiero', 'por', 'favor', 'hola', 'pedido', 'necesito', 'gracias',
            'como', 'esta', 'puedo', 'tengo', 'tambien', 'donde', 'cuando',
            'cual', 'cuanto', 'bueno', 'buena', 'buenos', 'buenas',
            'tienda', 'precio', 'pagar', 'direccion', 'ayuda', 'agregar',
            'quitar', 'mi', 'tu', 'este', 'ese', 'con', 'para', 'pero',
            'si', 'muy', 'mas', 'menos', 'otro', 'otra', 'bien',
            'entrega', 'tarjeta', 'efectivo', 'cuenta', 'total',
        ];

        // Portuguese indicator words (used as tiebreaker, not primary detection)
        $portugueseWords = [
            'quero', 'preciso', 'obrigado', 'obrigada', 'voce', 'entrega',
            'endereco', 'pagamento', 'carrinho', 'adicionar', 'remover',
            'pedir', 'loja', 'preco', 'ajuda', 'bom', 'boa', 'dia',
            'noite', 'tarde', 'tudo', 'nao', 'sim', 'pode', 'posso',
            'gostaria', 'quanto', 'qual', 'como', 'meu', 'minha',
            'esse', 'essa', 'aqui', 'agora', 'depois', 'ainda',
            'pra', 'pagar', 'cartao', 'dinheiro', 'troco',
        ];

        // Count matches
        $enCount = 0;
        $esCount = 0;
        $ptCount = 0;

        foreach ($words as $word) {
            if ($word === '') continue;
            if (in_array($word, $englishWords, true)) $enCount++;
            if (in_array($word, $spanishWords, true)) $esCount++;
            if (in_array($word, $portugueseWords, true)) $ptCount++;
        }

        // Also check for multi-word phrases (stronger signals)
        $englishPhrases = [
            'i want', 'i need', 'i would like', 'how much', 'thank you',
            'good morning', 'good afternoon', 'good evening', 'excuse me',
            'can you', 'could you', 'do you have', 'i\'d like', 'no thanks',
        ];
        foreach ($englishPhrases as $phrase) {
            if (mb_strpos($lower, $phrase) !== false) {
                $enCount += 3; // Phrases are stronger signals
            }
        }

        $spanishPhrases = [
            'por favor', 'buenos dias', 'buenas tardes', 'buenas noches',
            'me gustaria', 'cuanto cuesta', 'cuanto vale', 'no gracias',
            'quiero pedir', 'puede ser', 'esta bien', 'muchas gracias',
        ];
        foreach ($spanishPhrases as $phrase) {
            if (mb_strpos($lower, $phrase) !== false) {
                $esCount += 3;
            }
        }

        // Check for accented characters specific to Spanish (not in Portuguese)
        if (preg_match('/[ñ]/u', $lower)) {
            $esCount += 2;
        }

        // English-only characters/patterns
        if (preg_match('/\b(th|wh|sh)\w+/i', $lower)) {
            $enCount += 1;
        }

        // Scoring thresholds
        $enRatio = $enCount / max(1, $totalWords);
        $esRatio = $esCount / max(1, $totalWords);

        // English needs at least 15% word match or 3+ absolute matches
        if ($enCount >= 3 && $enRatio > 0.12 && $enCount > $esCount && $enCount > $ptCount) {
            return 'en';
        }

        // Spanish needs at least 10% word match or 3+ absolute matches
        if ($esCount >= 3 && $esRatio > 0.10 && $esCount > $enCount && $esCount > $ptCount) {
            return 'es';
        }

        // For short messages (1-3 words), use strict matching
        if ($totalWords <= 3) {
            if ($enCount > 0 && $enCount > $esCount && $enCount > $ptCount) return 'en';
            if ($esCount > 0 && $esCount > $enCount && $esCount > $ptCount) return 'es';
        }

        // Default: Portuguese
        return 'pt';

    } catch (Exception $e) {
        error_log("[ai-multilang] detectLanguage error: " . $e->getMessage());
        return 'pt';
    }
}


// =============================================================================
// 2. Multi-Language Prompt Modifiers
// =============================================================================

/**
 * Get a system prompt modifier for the detected language.
 *
 * Appended to the main system prompt to instruct Claude to respond
 * in the customer's language.
 *
 * @param string $language Language code: 'pt', 'en', 'es'
 * @param string $step     Current conversation step (for context)
 * @return string Prompt modifier (empty for Portuguese)
 */
function getMultilangPrompt(string $language, string $step): string {
    try {
        switch ($language) {
            case 'en':
                return "IMPORTANT: The customer speaks ENGLISH. Respond entirely in English. " .
                    "Use natural, friendly English. Convert all prices to 'R$ X.XX' format. " .
                    "Menu items should be in original Portuguese names but described in English. " .
                    "Keep the tone warm and helpful, like a local shop assistant speaking English.";

            case 'es':
                return "IMPORTANTE: El cliente habla ESPANOL. Responda completamente en espanol. " .
                    "Use un tono amigable y natural. Los precios deben ser en formato 'R$ X,XX'. " .
                    "Los nombres del menu mantener en portugues pero describir en espanol. " .
                    "Mantenga un tono calido y servicial.";

            case 'pt':
            default:
                return ''; // Portuguese is default, no modifier needed
        }
    } catch (Exception $e) {
        error_log("[ai-multilang] getMultilangPrompt error: " . $e->getMessage());
        return '';
    }
}


// =============================================================================
// 3. Multi-Language Greetings
// =============================================================================

/**
 * Get a greeting message in the detected language.
 *
 * @param string $language Language code: 'pt', 'en', 'es'
 * @return string Greeting message
 */
function getMultilangGreeting(string $language): string {
    try {
        // Time-aware greetings
        $hour = (int)date('H');

        switch ($language) {
            case 'en':
                if ($hour >= 5 && $hour < 12) {
                    return "Good morning! Welcome to SuperBora. I'm here to help you with your grocery order. What can I get for you today?";
                } elseif ($hour >= 12 && $hour < 18) {
                    return "Good afternoon! Welcome to SuperBora. I'm here to help you with your grocery order. What can I get for you today?";
                } else {
                    return "Good evening! Welcome to SuperBora. I'm here to help you with your grocery order. What can I get for you today?";
                }

            case 'es':
                if ($hour >= 5 && $hour < 12) {
                    return "Buenos dias! Bienvenido a SuperBora. Estoy aqui para ayudarte con tu pedido de supermercado. Que puedo hacer por ti hoy?";
                } elseif ($hour >= 12 && $hour < 18) {
                    return "Buenas tardes! Bienvenido a SuperBora. Estoy aqui para ayudarte con tu pedido de supermercado. Que puedo hacer por ti hoy?";
                } else {
                    return "Buenas noches! Bienvenido a SuperBora. Estoy aqui para ayudarte con tu pedido de supermercado. Que puedo hacer por ti hoy?";
                }

            case 'pt':
            default:
                if ($hour >= 5 && $hour < 12) {
                    return "Bom dia! Bem-vindo ao SuperBora. Estou aqui para ajudar com seu pedido de supermercado. O que posso fazer por voce hoje?";
                } elseif ($hour >= 12 && $hour < 18) {
                    return "Boa tarde! Bem-vindo ao SuperBora. Estou aqui para ajudar com seu pedido de supermercado. O que posso fazer por voce hoje?";
                } else {
                    return "Boa noite! Bem-vindo ao SuperBora. Estou aqui para ajudar com seu pedido de supermercado. O que posso fazer por voce hoje?";
                }
        }
    } catch (Exception $e) {
        error_log("[ai-multilang] getMultilangGreeting error: " . $e->getMessage());
        return "Ola! Bem-vindo ao SuperBora. Como posso ajudar?";
    }
}


// =============================================================================
// 4. Multi-Language Fallback Messages
// =============================================================================

/**
 * Get a fallback message in the detected language for a given step.
 *
 * Used when Claude API is unavailable and we need a static response.
 *
 * @param string $language Language code: 'pt', 'en', 'es'
 * @param string $step     Current conversation step
 * @return string Fallback message
 */
function getMultilangFallback(string $language, string $step): string {
    try {
        $fallbacks = _multilangGetFallbackMap();
        $langFallbacks = $fallbacks[$language] ?? $fallbacks['pt'];
        return $langFallbacks[$step] ?? $langFallbacks['default'];
    } catch (Exception $e) {
        error_log("[ai-multilang] getMultilangFallback error: " . $e->getMessage());
        return "Desculpe, estou com dificuldades tecnicas. Pode tentar novamente?";
    }
}

/**
 * Get error/apology message in the detected language.
 *
 * @param string $language Language code: 'pt', 'en', 'es'
 * @return string Error message
 */
function getMultilangError(string $language): string {
    try {
        switch ($language) {
            case 'en':
                return "I'm sorry, I'm having some technical difficulties right now. Could you please try again in a moment?";
            case 'es':
                return "Lo siento, estoy teniendo algunas dificultades tecnicas. Podrias intentar nuevamente en un momento?";
            case 'pt':
            default:
                return "Desculpe, estou com algumas dificuldades tecnicas no momento. Pode tentar novamente em um instante?";
        }
    } catch (Exception $e) {
        error_log("[ai-multilang] getMultilangError error: " . $e->getMessage());
        return "Desculpe, tente novamente.";
    }
}

/**
 * Get transfer-to-human message in the detected language.
 *
 * @param string $language Language code: 'pt', 'en', 'es'
 * @return string Transfer message
 */
function getMultilangTransfer(string $language): string {
    try {
        switch ($language) {
            case 'en':
                return "I'll transfer you to one of our agents who can better assist you. Please hold for a moment.";
            case 'es':
                return "Voy a transferirte a uno de nuestros agentes que puede ayudarte mejor. Por favor espera un momento.";
            case 'pt':
            default:
                return "Vou transferir voce para um de nossos atendentes que pode ajudar melhor. Aguarde um momento, por favor.";
        }
    } catch (Exception $e) {
        error_log("[ai-multilang] getMultilangTransfer error: " . $e->getMessage());
        return "Aguarde, transferindo para um atendente.";
    }
}

/**
 * Get goodbye/closing message in the detected language.
 *
 * @param string $language Language code: 'pt', 'en', 'es'
 * @return string Goodbye message
 */
function getMultilangGoodbye(string $language): string {
    try {
        switch ($language) {
            case 'en':
                return "Thank you for choosing SuperBora! Have a wonderful day. We look forward to serving you again!";
            case 'es':
                return "Gracias por elegir SuperBora! Que tengas un excelente dia. Esperamos atenderte nuevamente!";
            case 'pt':
            default:
                return "Obrigado por escolher o SuperBora! Tenha um otimo dia. Esperamos atende-lo novamente!";
        }
    } catch (Exception $e) {
        error_log("[ai-multilang] getMultilangGoodbye error: " . $e->getMessage());
        return "Obrigado! Tenha um otimo dia.";
    }
}


// =============================================================================
// 5. Internal Helpers
// =============================================================================

/**
 * Complete fallback map for all steps and languages.
 * @internal
 * @return array Nested array [language][step] => message
 */
function _multilangGetFallbackMap(): array {
    return [
        'pt' => [
            'greeting' => 'Ola! Bem-vindo ao SuperBora. Como posso ajudar voce hoje?',
            'identify_store' => 'De qual loja voce gostaria de pedir? Temos varias opcoes disponiveis para voce.',
            'take_order' => 'O que voce gostaria de pedir? Pode me dizer os itens e as quantidades desejadas.',
            'review_order' => 'Vamos revisar seu pedido. Deseja adicionar ou remover algum item antes de confirmar?',
            'address' => 'Para qual endereco devemos entregar seu pedido?',
            'payment' => 'Como voce gostaria de pagar? Aceitamos PIX, cartao de credito e cartao de debito.',
            'confirm_order' => 'Posso confirmar seu pedido? Esta tudo certo para finalizar?',
            'submit_order' => 'Seu pedido foi enviado com sucesso! Voce pode acompanhar pelo app. Obrigado!',
            'support' => 'Entendo sua situacao. Como posso ajudar a resolver isso para voce?',
            'order_status' => 'Vou verificar o status do seu pedido. Um momento, por favor.',
            'cancel_order' => 'Entendi que voce deseja cancelar. Posso ajudar com isso.',
            'transfer_agent' => 'Vou transferir voce para um atendente humano. Aguarde um momento.',
            'end' => 'Obrigado por usar o SuperBora! Tenha um otimo dia.',
            'default' => 'Desculpe, estou com uma dificuldade momentanea. Pode repetir, por favor?',
        ],
        'en' => [
            'greeting' => 'Hello! Welcome to SuperBora. How can I help you today?',
            'identify_store' => 'Which store would you like to order from? We have several options available.',
            'take_order' => 'What would you like to order? You can tell me the items and quantities.',
            'review_order' => 'Let\'s review your order. Would you like to add or remove any items before confirming?',
            'address' => 'What is the delivery address for your order?',
            'payment' => 'How would you like to pay? We accept PIX, credit card, and debit card.',
            'confirm_order' => 'Can I confirm your order? Is everything correct to finalize?',
            'submit_order' => 'Your order has been placed successfully! You can track it in the app. Thank you!',
            'support' => 'I understand your situation. How can I help resolve this for you?',
            'order_status' => 'Let me check your order status. One moment please.',
            'cancel_order' => 'I understand you\'d like to cancel. I can help with that.',
            'transfer_agent' => 'I\'ll transfer you to a human agent. Please hold for a moment.',
            'end' => 'Thank you for using SuperBora! Have a great day.',
            'default' => 'I\'m sorry, I\'m having a momentary difficulty. Could you please repeat that?',
        ],
        'es' => [
            'greeting' => 'Hola! Bienvenido a SuperBora. Como puedo ayudarte hoy?',
            'identify_store' => 'De cual tienda te gustaria pedir? Tenemos varias opciones disponibles.',
            'take_order' => 'Que te gustaria pedir? Puedes decirme los items y las cantidades.',
            'review_order' => 'Vamos a revisar tu pedido. Quieres agregar o quitar algun item antes de confirmar?',
            'address' => 'A cual direccion debemos entregar tu pedido?',
            'payment' => 'Como te gustaria pagar? Aceptamos PIX, tarjeta de credito y tarjeta de debito.',
            'confirm_order' => 'Puedo confirmar tu pedido? Esta todo correcto para finalizar?',
            'submit_order' => 'Tu pedido fue enviado con exito! Puedes seguirlo en la app. Gracias!',
            'support' => 'Entiendo tu situacion. Como puedo ayudarte a resolver esto?',
            'order_status' => 'Voy a verificar el estado de tu pedido. Un momento por favor.',
            'cancel_order' => 'Entiendo que deseas cancelar. Puedo ayudarte con eso.',
            'transfer_agent' => 'Voy a transferirte a un agente humano. Espera un momento por favor.',
            'end' => 'Gracias por usar SuperBora! Que tengas un excelente dia.',
            'default' => 'Lo siento, estoy teniendo una dificultad momentanea. Podrias repetir por favor?',
        ],
    ];
}
