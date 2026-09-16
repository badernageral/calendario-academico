<?php
declare(strict_types=1);

/**
 * Verifica se há uma versão mais nova publicada no GitHub (tag/release do
 * repositório), no máximo 1x por dia. O resultado da consulta HTTP fica
 * gravado na tabela `config` (mesma usada por cfg()/cfgSalvar()), para não
 * bater na API do GitHub — nem segurar a abertura do sistema por uma chamada
 * de rede — em toda requisição.
 */

const ATUALIZACAO_REPO      = 'badernageral/calendario-academico';
const ATUALIZACAO_INTERVALO = 86400; // 1 dia

// Só quando há atualização disponível — usado no aviso discreto do menu/topbar.
function atualizacaoDisponivel(): ?array
{
    $s = atualizacaoStatus();

    if ($s['tag'] === null || $s['atualizado']) {
        return null;
    }

    return [
        'versao_disponivel' => $s['versao_disponivel'],
        'url'               => $s['url_release'],
    ];
}

// Status completo (atualizado ou não, com falha ou sem) — usado na tela de Atualizações.
function atualizacaoStatus(): array
{
    [$tag, $verificadoEm] = atualizacaoTagMaisRecente();
    $versaoDisponivel     = $tag !== null ? ltrim($tag, 'vV') : null;

    return [
        'versao_atual'      => APP_VERSION,
        'versao_disponivel' => $versaoDisponivel,
        'tag'               => $tag,
        'atualizado'        => $tag !== null ? version_compare($versaoDisponivel, APP_VERSION, '<=') : null,
        'verificado_em'     => $verificadoEm,
        'url_release'       => $tag !== null ? 'https://github.com/' . ATUALIZACAO_REPO . '/releases/tag/' . $tag : null,
        'url_releases'      => 'https://github.com/' . ATUALIZACAO_REPO . '/releases',
    ];
}

/** @return array{0: ?string, 1: ?int} [tag, verificadoEm] */
function atualizacaoTagMaisRecente(): array
{
    $db           = db();
    $verificadoEm = (int) cfg('atualizacao_verificado_em', '0');

    if ($verificadoEm > 0 && (time() - $verificadoEm) < ATUALIZACAO_INTERVALO) {
        $tag = cfg('atualizacao_tag');
        return [$tag !== '' ? $tag : null, $verificadoEm];
    }

    $tag  = atualizacaoConsultarGithub();
    $agora = time();

    // Grava mesmo em falha (tag vazia), para não tentar de novo a cada
    // requisição enquanto a API do GitHub estiver fora ou sem rede.
    cfgSalvar($db, 'atualizacao_verificado_em', (string) $agora);
    cfgSalvar($db, 'atualizacao_tag', $tag ?? '');

    return [$tag, $agora];
}

function atualizacaoConsultarGithub(): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init('https://api.github.com/repos/' . ATUALIZACAO_REPO . '/releases/latest');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: calendario-academico-update-checker',
            'Accept: application/vnd.github+json',
        ],
    ]);
    $resposta = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resposta === false || $status !== 200) {
        return null;
    }

    $dados = json_decode($resposta, true);

    return is_array($dados) && !empty($dados['tag_name']) ? (string) $dados['tag_name'] : null;
}
