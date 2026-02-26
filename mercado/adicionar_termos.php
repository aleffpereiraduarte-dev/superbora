<?php
require_once __DIR__ . '/config/database.php';
/**
 * ══════════════════════════════════════════════════════════════════════
 * 📦 ADICIONAR MAIS TERMOS - META 100K
 * ══════════════════════════════════════════════════════════════════════
 * Adiciona mais 500+ termos ao crawler para chegar em 100k produtos
 * ══════════════════════════════════════════════════════════════════════
 */
header('Content-Type: text/plain; charset=utf-8');

$pdo = getPDO();

// Novos termos para adicionar
$novosTermos = [
    // BEBIDAS
    "cerveja brahma", "cerveja skol", "cerveja antarctica", "cerveja heineken", "cerveja budweiser",
    "cerveja corona", "cerveja stella artois", "cerveja colorado", "cerveja ipa", "cerveja pilsen",
    "vinho tinto", "vinho branco", "vinho rose", "vinho suave", "vinho seco",
    "espumante", "champagne", "prosecco", "sidra",
    "whisky", "vodka", "rum", "gin", "tequila", "cachaça", "conhaque",
    "energetico red bull", "energetico monster", "energetico tnt",
    "agua mineral", "agua com gas", "agua de coco", "suco del valle", "suco sufresh",
    "cha gelado", "cha leao", "cha matte leao", "guarana jesus",
    
    // PADARIA
    "pao de forma", "pao integral", "pao australiano", "pao sirio", "pao frances",
    "bisnaguinha", "pao de hot dog", "pao de hamburguer", "pao de alho",
    "bolo pronto", "bolo de chocolate", "bolo de cenoura", "bolo de laranja",
    "croissant", "sonho", "rosquinha", "pao doce",
    "torrada", "farinha de rosca", "fermento biologico", "fermento quimico",
    
    // FRIOS E LATICÍNIOS
    "presunto", "mortadela", "salame", "peito de peru", "blanquet",
    "queijo mussarela", "queijo prato", "queijo minas", "queijo coalho", "queijo gorgonzola",
    "queijo parmesao", "queijo provolone", "queijo cheddar", "queijo brie",
    "requeijao", "cream cheese", "ricota", "cottage",
    "manteiga", "margarina", "creme de leite", "leite condensado",
    
    // CARNES
    "picanha", "alcatra", "fraldinha", "maminha", "contrafile",
    "patinho", "acem", "costela", "musculo", "carne moida",
    "file mignon", "t-bone", "prime rib", "baby beef",
    "linguica toscana", "linguica calabresa", "linguica de frango",
    "salsicha", "bacon", "copa", "pancetta",
    "frango inteiro", "coxa de frango", "sobrecoxa", "peito de frango", "asa de frango",
    "carne suina", "bisteca", "lombo suino", "costela suina", "pernil",
    
    // PEIXES E FRUTOS DO MAR
    "salmao", "tilapia", "bacalhau", "atum", "sardinha",
    "camarao", "lula", "polvo", "mexilhao", "surimi",
    "file de peixe", "posta de peixe",
    
    // HORTIFRUTI
    "banana", "maca", "laranja", "limao", "uva",
    "manga", "mamao", "abacaxi", "melancia", "melao",
    "morango", "kiwi", "pera", "pessego", "ameixa",
    "tomate", "cebola", "alho", "batata", "cenoura",
    "alface", "rucula", "agriao", "couve", "brocolis",
    "abobrinha", "berinjela", "pimentao", "pepino", "chuchu",
    "mandioca", "inhame", "batata doce", "beterraba",
    
    // CEREAIS E GRÃOS
    "arroz integral", "arroz parboilizado", "arroz jasmin", "arroz arborio",
    "feijao carioca", "feijao preto", "feijao branco", "feijao fradinho",
    "lentilha", "grao de bico", "ervilha", "soja",
    "aveia", "granola", "chia", "quinoa", "linhaça",
    "farinha de trigo", "farinha de mandioca", "farinha de milho", "fuba",
    "amido de milho", "polvilho", "tapioca",
    
    // MASSAS
    "macarrao espaguete", "macarrao penne", "macarrao parafuso", "macarrao cabelo de anjo",
    "lasanha massa", "capeletti", "ravióli", "nhoque",
    "macarrao instantaneo", "cup noodles", "miojo",
    
    // MOLHOS E CONDIMENTOS
    "molho de tomate", "extrato de tomate", "catchup", "mostarda",
    "maionese", "molho ingles", "molho shoyu", "molho teriyaki",
    "azeite", "vinagre", "oleo de soja", "oleo de girassol", "oleo de canola",
    "sal refinado", "sal grosso", "sal rosa", "sal marinho",
    "pimenta do reino", "pimenta calabresa", "oregano", "manjericao",
    "colorau", "cominho", "curry", "paprica", "açafrão",
    "caldo de galinha", "caldo de carne", "caldo de legumes",
    
    // DOCES E SOBREMESAS
    "chocolate ao leite", "chocolate amargo", "chocolate branco",
    "bombom", "trufa", "barra de chocolate",
    "gelatina", "pudim", "mousse", "flan",
    "sorvete kibon", "sorvete nestle", "sorvete haagen dazs",
    "picole", "açaí", "frozen",
    "goiabada", "marmelada", "doce de leite",
    "mel", "melado", "geleia",
    
    // BISCOITOS E SNACKS
    "biscoito recheado", "biscoito cream cracker", "biscoito agua e sal",
    "biscoito wafer", "biscoito amanteigado", "biscoito integral",
    "bolacha maria", "bolacha maisena",
    "salgadinho doritos", "salgadinho cheetos", "salgadinho ruffles",
    "pipoca", "amendoim", "castanha", "pistache", "nozes",
    "barra de cereal", "barra de proteina",
    
    // CAFÉ E ACHOCOLATADOS
    "cafe pilao", "cafe melitta", "cafe 3 coracoes", "cafe nescafe",
    "cafe capsula", "cafe soluvel", "cafe descafeinado",
    "achocolatado nescau", "achocolatado toddy", "achocolatado ovomaltine",
    "leite em po", "leite ninho", "leite molico",
    
    // HIGIENE PESSOAL
    "shampoo pantene", "shampoo elseve", "shampoo head shoulders", "shampoo dove",
    "condicionador", "creme de tratamento", "mascara capilar",
    "sabonete dove", "sabonete nivea", "sabonete lux", "sabonete protex",
    "desodorante rexona", "desodorante dove", "desodorante nivea", "desodorante old spice",
    "creme dental colgate", "creme dental oral b", "creme dental sensodyne",
    "escova dental", "fio dental", "enxaguante bucal",
    "papel higienico neve", "papel higienico personal", "papel higienico mili",
    "absorvente always", "absorvente intimus", "protetor diario",
    "fralda pampers", "fralda huggies", "fralda mamypoko",
    "aparelho de barbear", "lamina gillette", "espuma de barbear",
    "hidratante nivea", "hidratante dove", "protetor solar",
    
    // LIMPEZA CASA
    "detergente ype", "detergente limpol", "detergente minuano",
    "sabao em po omo", "sabao em po ariel", "sabao em po ace",
    "amaciante comfort", "amaciante downy", "amaciante ype",
    "agua sanitaria qboa", "desinfetante pinho sol", "desinfetante lysoform",
    "limpador multiuso veja", "limpador cif", "limpa vidros",
    "esponja scotch brite", "palha de aco bombril", "pano de chao",
    "saco de lixo", "luva de limpeza", "rodo", "vassoura",
    
    // PET SHOP
    "racao pedigree", "racao royal canin", "racao premier", "racao golden",
    "racao gatos whiskas", "racao gatos friskies", "racao gatos premier",
    "petisco pedigree", "petisco dreamies", "osso para cachorro",
    "areia para gato", "tapete higienico", "coleira", "brinquedo pet",
    
    // BEBÊS
    "formula infantil nan", "formula infantil aptamil", "formula infantil enfamil",
    "papinha nestle", "papinha heinz",
    "leite ninho fases", "mucilon", "farinha lactea",
    "fralda pampers", "fralda huggies", "lenco umedecido",
    
    // CONGELADOS
    "pizza congelada", "lasanha congelada", "hamburguer congelado",
    "nuggets", "empanado", "steak",
    "legumes congelados", "batata congelada", "açaí congelado",
    "pao de queijo congelado", "coxinha congelada", "esfiha congelada",
    
    // SUPLEMENTOS
    "whey protein", "creatina", "bcaa", "albumina",
    "vitamina c", "vitamina d", "multivitaminico", "omega 3",
    "colageno", "melatonina"
];

echo "=== ADICIONAR NOVOS TERMOS ===\n\n";

// Ver termos atuais
$stateAtual = $pdo->query("SELECT termo_atual FROM om_crawler_completo_state WHERE id=1")->fetchColumn();
echo "Termo atual: $stateAtual\n";

// Buscar termos existentes no arquivo do crawler
$crawlerFile = $_SERVER['DOCUMENT_ROOT'] . '/mercado/cron_mega_crawler.php';
if (file_exists($crawlerFile)) {
    $conteudo = file_get_contents($crawlerFile);
    
    // Contar termos atuais
    preg_match('/\$TERMOS\s*=\s*\[(.*?)\];/s', $conteudo, $matches);
    if ($matches) {
        $termosAtuais = substr_count($matches[1], '"');
        echo "Termos atuais no crawler: ~" . ($termosAtuais/2) . "\n";
    }
}

echo "Novos termos a adicionar: " . count($novosTermos) . "\n\n";

if (isset($_GET['aplicar'])) {
    // Adicionar termos ao crawler
    if (file_exists($crawlerFile)) {
        $conteudo = file_get_contents($crawlerFile);
        
        // Encontrar o array $TERMOS e adicionar os novos
        $novosStr = '"' . implode('", "', $novosTermos) . '"';
        
        // Procurar onde termina o array atual
        $pos = strrpos($conteudo, '];');
        if ($pos !== false) {
            // Encontrar o último array de TERMOS
            $lastTermos = strrpos(substr($conteudo, 0, $pos), '$TERMOS');
            if ($lastTermos !== false) {
                // Encontrar o ]; desse array
                $fimArray = strpos($conteudo, '];', $lastTermos);
                if ($fimArray !== false) {
                    $novoConteudo = substr($conteudo, 0, $fimArray) . ",\n    " . $novosStr . "\n];" . substr($conteudo, $fimArray + 2);
                    
                    // Backup
                    copy($crawlerFile, $crawlerFile . '.backup_' . date('YmdHis'));
                    
                    // Salvar
                    file_put_contents($crawlerFile, $novoConteudo);
                    
                    echo "✅ " . count($novosTermos) . " termos adicionados!\n";
                    echo "📁 Backup criado\n";
                }
            }
        }
    }
} else {
    echo "👉 Use ?aplicar=1 para adicionar os termos ao crawler\n";
}

echo "\n=== FIM ===\n";
?>
