#!/usr/bin/env node
/*
 * AWA Motos — declarações mortas no CSS: metodo CONSERVADOR.
 *
 * A v1 deste script mentiu por 2 motivos (medidos em 05/10/2026):
 *   1) fazia split(',') no seletor e quebrava :is()/:where() que tem virgula
 *      interna -> agrupava seletores DIFERENTES e inventava conflito;
 *   2) comparava pelo CSSOM do navegador, que ja RESOLVE var() -> comparava
 *      "var(--awa-ink)" com "rgb(255,255,255)" (a MESMA declaracao) e contava
 *      como sobrescrita; e contava "position: static" (valor inicial) como override.
 *
 * Agora: parseia o TEXTO CRU, so compara seletores IDENTICOS (split so em virgula
 * de nivel 0), preserva var(), ignora valores iniciais, e usa memoria O(grupos).
 * O numero que sai daqui e um PISO conservador, nao um teto.
 *
 * Uso: node tools/awa-css-morto2.mjs <arquivo.css> [--top=N] [--json=out.json]
 */
import fs from 'node:fs';

const args = process.argv.slice(2);
const arquivo = args.find(a => !a.startsWith('--'));
const topN = parseInt((args.find(a => a.startsWith('--top=')) || '--top=25').split('=')[1], 10);
const jsonOut = (args.find(a => a.startsWith('--json=')) || '').split('=')[1];
if (!arquivo) { console.error('uso: node tools/awa-css-morto2.mjs <arquivo.css>'); process.exit(1); }

const bruto = fs.readFileSync(arquivo, 'utf8');
const semComent = bruto.replace(/\/\*[\s\S]*?\*\//g, c => ' '.repeat(c.length));

// nomes de secao (comentarios "SEÇÃO NN") com posicao, para o relatorio ser acionavel
const secoes = [];
for (const m of bruto.matchAll(/\/\*\s*(?:={3,}\s*)?(SE[CÇ][AÃ]O\s+[\d.]+)/gi)) {
  secoes.push({ pos: m.index, nome: m[1].toUpperCase() });
}
const secaoEm = (pos) => { let r = '(sem secao)'; for (const s of secoes) { if (s.pos <= pos) r = s.nome; else break; } return r; };

// split em virgulas de nivel 0 (respeita parenteses de :is/:where/:not)
function divideSeletores(txt) {
  const out = []; let nivel = 0, atual = '';
  for (const ch of txt) {
    if (ch === '(') nivel++;
    else if (ch === ')') nivel--;
    if (ch === ',' && nivel === 0) { out.push(atual); atual = ''; }
    else atual += ch;
  }
  if (atual.trim()) out.push(atual);
  return out.map(s => s.trim().replace(/\s+/g, ' ')).filter(Boolean);
}

// valores que sao o INITIAL da propriedade -> nao contam como override
const INICIAIS = {
  'position': ['static'], 'display': ['inline'], 'visibility': ['visible'],
  'opacity': ['1'], 'float': ['none'], 'overflow': ['visible'],
  'background': ['transparent', 'none'], 'border': ['none', '0'],
  'transform': ['none'], 'z-index': ['auto'], 'min-height': ['auto', '0'],
  'max-height': ['none'], 'width': ['auto'], 'height': ['auto'], 'margin': ['0'],
  'padding': ['0'], 'text-align': ['start', 'left'], 'font-weight': ['400', 'normal']
};

// estado: grupos[chave] = { ultimo, mortas:[], n }
const grupos = new Map();
let regras = 0, declaracoes = 0;
const pilha = [];   // contexto @media/@layer/@supports
let i = 0;

while (i < semComent.length) {
  const abre = semComent.indexOf('{', i);
  if (abre === -1) break;
  const preludio = semComent.slice(i, abre).trim();

  // fecha correspondente
  let nivel = 1, j = abre + 1;
  while (j < semComent.length && nivel > 0) {
    const c = semComent[j];
    if (c === '{') nivel++;
    else if (c === '}') nivel--;
    j++;
  }
  const corpo = semComent.slice(abre + 1, j - 1);
  const ctx = pilha.join(' > ');

  if (preludio.startsWith('@')) {
    // at-rule com bloco: se for condicional, entra no contexto; se for @keyframes/@font-face, ignora
    const nome = preludio.split(/\s/)[0].toLowerCase();
    if (nome === '@media' || nome === '@supports' || nome === '@layer' || nome === '@container') {
      pilha.push(preludio.split('\n')[0].slice(0, 56));
      // varre o interior sem recursao: processa os blocos internos em linha
      const interno = semComent.slice(abre + 1, j - 1);
      let k = 0;
      while (k < interno.length) {
        const ab2 = interno.indexOf('{', k);
        if (ab2 === -1) break;
        const pre2 = interno.slice(k, ab2).trim();
        let n2 = 1, j2 = ab2 + 1;
        while (j2 < interno.length && n2 > 0) { const ch = interno[j2]; if (ch === '{') n2++; else if (ch === '}') n2--; j2++; }
        const cor2 = interno.slice(ab2 + 1, j2 - 1);
        if (pre2 && !pre2.startsWith('@')) {
          regras++;
          const sel = pilha.join(' > ');
          for (const s of divideSeletores(pre2)) {
            for (const decl of cor2.split(';')) {
              const p = decl.indexOf(':'); if (p === -1) continue;
              const prop = decl.slice(0, p).trim().toLowerCase();
              let valor = decl.slice(p + 1).trim();
              if (!prop || !valor) continue;
              const imp = /!important/i.test(valor);
              valor = valor.replace(/!important/i, '').trim();
              declaracoes++;
              const chave = s + ' ||| ' + prop + ' ||| ' + sel;
              const g = grupos.get(chave);
              if (g) {
                const ini = (INICIAIS[prop] || []).includes(g.ultimo.valor.toLowerCase());
                if (g.ultimo.valor !== valor && !ini) g.mortas.push(g.ultimo);
                g.ultimo = { valor, imp, secao: secaoEm(abre) };
              } else {
                grupos.set(chave, { ultimo: { valor, imp, secao: secaoEm(abre) }, mortas: [] });
              }
            }
          }
        } else if (pre2.startsWith('@')) {
          // @media aninhado: entra e sai do contexto
          pilha.push(pre2.split('\n')[0].slice(0, 56));
          const cor3 = cor2;
          if (cor3.indexOf('{') !== -1) {
            // processa um nivel a mais de forma simples
            let k3 = 0;
            while (k3 < cor3.length) {
              const ab3 = cor3.indexOf('{', k3); if (ab3 === -1) break;
              const pre3 = cor3.slice(k3, ab3).trim();
              let n3 = 1, j3 = ab3 + 1;
              while (j3 < cor3.length && n3 > 0) { const ch = cor3[j3]; if (ch === '{') n3++; else if (ch === '}') n3--; j3++; }
              const cor4 = cor3.slice(ab3 + 1, j3 - 1);
              if (pre3 && !pre3.startsWith('@')) {
                regras++;
                const sel3 = pilha.join(' > ');
                for (const s of divideSeletores(pre3)) {
                  for (const decl of cor4.split(';')) {
                    const p = decl.indexOf(':'); if (p === -1) continue;
                    const prop = decl.slice(0, p).trim().toLowerCase();
                    let valor = decl.slice(p + 1).trim();
                    if (!prop || !valor) continue;
                    const imp = /!important/i.test(valor);
                    valor = valor.replace(/!important/i, '').trim();
                    declaracoes++;
                    const chave = s + ' ||| ' + prop + ' ||| ' + sel3;
                    const g = grupos.get(chave);
                    if (g) {
                      const ini = (INICIAIS[prop] || []).includes(g.ultimo.valor.toLowerCase());
                      if (g.ultimo.valor !== valor && !ini) g.mortas.push(g.ultimo);
                      g.ultimo = { valor, imp, secao: secaoEm(abre) };
                    } else {
                      grupos.set(chave, { ultimo: { valor, imp, secao: secaoEm(abre) }, mortas: [] });
                    }
                  }
                }
              }
              k3 = j3;
            }
          }
          pilha.pop();
        }
        k = j2;
      }
      pilha.pop();
    }
    i = j;
    continue;
  }

  if (preludio) {
    regras++;
    for (const s of divideSeletores(preludio)) {
      for (const decl of corpo.split(';')) {
        const p = decl.indexOf(':'); if (p === -1) continue;
        const prop = decl.slice(0, p).trim().toLowerCase();
        let valor = decl.slice(p + 1).trim();
        if (!prop || !valor) continue;
        const imp = /!important/i.test(valor);
        valor = valor.replace(/!important/i, '').trim();
        declaracoes++;
        const chave = s + ' ||| ' + prop + ' ||| ' + ctx;
        const g = grupos.get(chave);
        if (g) {
          const ini = (INICIAIS[prop] || []).includes(g.ultimo.valor.toLowerCase());
          if (g.ultimo.valor !== valor && !ini) g.mortas.push(g.ultimo);
          g.ultimo = { valor, imp, secao: secaoEm(i) };
        } else {
          grupos.set(chave, { ultimo: { valor, imp, secao: secaoEm(i) }, mortas: [] });
        }
      }
    }
  }
  i = j;
}

let totalMortas = 0;
const porSecao = new Map();
const amostra = [];
for (const [chave, g] of grupos) {
  if (!g.mortas.length) continue;
  totalMortas += g.mortas.length;
  for (const m of g.mortas) {
    if (!porSecao.has(m.secao)) porSecao.set(m.secao, 0);
    porSecao.set(m.secao, porSecao.get(m.secao) + 1);
    if (amostra.length < 300) {
      const [sel, prop] = chave.split(' ||| ');
      amostra.push({ sel: sel.slice(0, 76), prop, morto: m.valor.slice(0, 40), secaoMorta: m.secao, vence: g.ultimo.valor.slice(0, 40), secaoVence: g.ultimo.secao });
    }
  }
}
const ranking = [...porSecao.entries()].map(([secao, n]) => ({ secao, mortas: n })).sort((a, b) => b.mortas - a.mortas);

console.log('=============================================================');
console.log('DECLARACOES MORTAS — metodo CONSERVADOR (piso, nao teto)');
console.log('=============================================================');
console.log('  arquivo ....................... ' + arquivo);
console.log('  tamanho ....................... ' + (bruto.length / 1024).toFixed(0) + ' KB');
console.log('  regras ........................ ' + regras.toLocaleString('pt-BR'));
console.log('  declaracoes ................... ' + declaracoes.toLocaleString('pt-BR'));
console.log('  grupos distintos .............. ' + grupos.size.toLocaleString('pt-BR'));
console.log('  *** DECLARACOES MORTAS ........ ' + totalMortas.toLocaleString('pt-BR') +
  ' (' + (declaracoes ? (totalMortas / declaracoes * 100).toFixed(1) : '0') + '%) ***');
console.log('');
console.log('  O que este numero NAO inclui (por isso e piso):');
console.log('    - conflito entre seletores DIFERENTES (so compara seletor identico)');
console.log('    - sobrescrita por !important de outra regra');
console.log('    - regra que nunca casa em nenhuma pagina (precisa do navegador)');
console.log('');
console.log('--- TOP ' + topN + ' SECOES COM MAIS CODIGO MORTO ---');
ranking.slice(0, topN).forEach((r, n) => {
  console.log('  ' + String(n + 1).padStart(2) + '. ' + String(r.mortas).padStart(5) + '  ' + r.secao);
});
console.log('');
console.log('--- AMOSTRA DE 12 CONFLITOS (mesmo seletor, mesma propriedade) ---');
amostra.slice(0, 12).forEach(a => {
  console.log('  ' + a.sel);
  console.log('     ' + a.prop + ': ' + a.morto + '      [MORTA · ' + a.secaoMorta + ']');
  console.log('     ' + a.prop + ': ' + a.vence + '      [VENCE · ' + a.secaoVence + ']');
});

if (jsonOut) {
  fs.writeFileSync(jsonOut, JSON.stringify({
    arquivo, tamanhoBytes: bruto.length, regras, declaracoes, grupos: grupos.size,
    mortas: totalMortas, ranking, amostra
  }, null, 2));
  console.log('\n  JSON: ' + jsonOut);
}
