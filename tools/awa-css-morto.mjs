#!/usr/bin/env node
/*
 * AWA Motos — mapeia DECLARACOES MORTAS no design system.
 *
 * Problema que resolve: o awa-design-system.css tem ~9.600 linhas e secoes
 * numeradas que se contradizem (ex.: SECAO 34 diz width:100% no cookie banner,
 * SECAO 51.31 posterior diz width:min(420px)). A anterior e codigo morto — mas
 * ninguem sabe QUANTAS sao nem QUAIS, entao toda correcao vira briga de
 * especificidade (foi o que aconteceu com o breadcrumb em 05/10/2026).
 *
 * Metodo: parseia o CSS, agrupa por (seletor normalizado + propriedade) dentro
 * do mesmo contexto (@media/@layer), e marca como MORTA a declaracao que e
 * sobrescrita por uma posterior de especificidade igual ou maior.
 *
 * Uso: node tools/awa-css-morto.mjs <arquivo.css> [--top=N] [--json=saida.json]
 */
import fs from 'node:fs';

const args = process.argv.slice(2);
const arquivo = args.find(a => !a.startsWith('--'));
const topN = parseInt((args.find(a => a.startsWith('--top=')) || '--top=40').split('=')[1], 10);
const jsonOut = (args.find(a => a.startsWith('--json=')) || '').split('=')[1];

if (!arquivo) { console.error('uso: node tools/awa-css-morto.mjs <arquivo.css> [--top=N]'); process.exit(1); }

const bruto = fs.readFileSync(arquivo, 'utf8');

/* ---------- 1. tokenizer simples, ciente de strings/comentarios ---------- */
// remove comentarios guardando a posicao (para saber a SECAO de cada regra)
const secoes = [];
const reSecao = /\/\*\s*(?:={3,}\s*)?(SE[CÇ][AÃ]O\s+[\d.]+[^*]*)/gi;
let m;
while ((m = reSecao.exec(bruto)) !== null) {
  secoes.push({ pos: m.index, nome: m[1].split('\n')[0].trim().slice(0, 70) });
}
const secaoEm = (pos) => {
  let achada = '(sem secao)';
  for (const s of secoes) { if (s.pos <= pos) achada = s.nome; else break; }
  return achada;
};

const semComentario = bruto.replace(/\/\*[\s\S]*?\*\//g, (c) => ' '.repeat(c.length));

/* ---------- 2. parse: percorre blocos mantendo o contexto ---------- */
const declaracoes = []; // { seletor, prop, valor, imp, ctx, pos, secao, ordem }
let ordem = 0;
let i = 0;
const pilha = []; // contexto (@media / @layer / @supports)

function leBloco() {
  while (i < semComentario.length) {
    const abre = semComentario.indexOf('{', i);
    if (abre === -1) return;
    const preludio = semComentario.slice(i, abre).trim();
    // acha o fecha correspondente
    let nivel = 1, j = abre + 1;
    while (j < semComentario.length && nivel > 0) {
      const c = semComentario[j];
      if (c === '{') nivel++;
      else if (c === '}') nivel--;
      j++;
    }
    const corpo = semComentario.slice(abre + 1, j - 1);

    if (preludio.startsWith('@')) {
      // grupo condicional: entra no contexto e recursa
      pilha.push(preludio.split('\n')[0].slice(0, 60));
      const salvoI = i, salvoFim = j;
      i = abre + 1;
      leBloco();
      i = salvoFim;
      pilha.pop();
      i = salvoFim;
      continue;
    }

    if (preludio) {
      const ctx = pilha.join(' > ');
      // cada seletor do grupo
      preludio.split(',').forEach(sel => {
        const s = sel.trim().replace(/\s+/g, ' ');
        if (!s) return;
        for (const decl of corpo.split(';')) {
          const p = decl.indexOf(':');
          if (p === -1) continue;
          const prop = decl.slice(0, p).trim().toLowerCase();
          let valor = decl.slice(p + 1).trim();
          if (!prop || !valor) continue;
          const imp = /!important/i.test(valor);
          valor = valor.replace(/!important/i, '').trim();
          declaracoes.push({
            seletor: s, prop, valor, imp, ctx,
            ordem: ordem++, pos: i,
            secao: secaoEm(i)
          });
        }
      });
    }
    i = j;
  }
}
leBloco();

/* ---------- 3. agrupa e acha as MORTAS ---------- */
// chave: seletor normalizado + propriedade + contexto. Dentro do mesmo contexto,
// a ultima declaracao de mesma especificidade vence. Sem @layer, !important vence.
const grupos = new Map();
for (const d of declaracoes) {
  const k = d.seletor + ' ||| ' + d.prop + ' ||| ' + d.ctx;
  if (!grupos.has(k)) grupos.set(k, []);
  grupos.get(k).push(d);
}

const mortas = [];
for (const [k, lista] of grupos) {
  if (lista.length < 2) continue;
  // ordena por ordem de aparicao
  lista.sort((a, b) => a.ordem - b.ordem);
  const ultima = lista[lista.length - 1];
  for (let n = 0; n < lista.length - 1; n++) {
    const d = lista[n];
    // morre se a ultima tem especificidade >= (aproximacao: mesmo seletor = igual)
    // e o valor e diferente, e o !important nao salva
    const perdePorImportance = ultima.imp || !d.imp;
    if (d.valor !== ultima.valor && perdePorImportance) {
      mortas.push({
        ...d,
        valorFinal: ultima.valor,
        secaoFinal: ultima.secao,
        motivo: ultima.imp === d.imp ? 'sobrescrita por regra posterior identica' : 'perde para !important posterior'
      });
    }
  }
}

/* ---------- 4. numeros por secao ---------- */
const porSecao = {};
for (const d of mortas) {
  porSecao[d.secao] = porSecao[d.secao] || { n: 0, props: new Set() };
  porSecao[d.secao].n++;
  porSecao[d.secao].props.add(d.prop);
}
const ranking = Object.entries(porSecao)
  .map(([secao, v]) => ({ secao, mortas: v.n, props: [...v.props].slice(0, 5).join(', ') }))
  .sort((a, b) => b.mortas - a.mortas);

/* ---------- 5. relatorio ---------- */
const total = declaracoes.length;
console.log('=============================================================');
console.log('DECLARACOES MORTAS NO DESIGN SYSTEM');
console.log('=============================================================');
console.log('  arquivo .................. ' + arquivo);
console.log('  tamanho .................. ' + (bruto.length / 1024).toFixed(0) + ' KB');
console.log('  declaracoes totais ....... ' + total.toLocaleString('pt-BR'));
console.log('  GRUPOS com conflito ...... ' + [...grupos.values()].filter(l => l.length > 1).length.toLocaleString('pt-BR'));
console.log('  DECLARACOES MORTAS ....... ' + mortas.length.toLocaleString('pt-BR') +
  '  (' + (mortas.length / total * 100).toFixed(1) + '% do arquivo)');
console.log('  secoes afetadas .......... ' + ranking.length);
console.log('');
console.log('--- TOP ' + topN + ' SECOES COM MAIS CODIGO MORTO ---');
ranking.slice(0, topN).forEach((r, n) => {
  console.log('  ' + String(n + 1).padStart(2) + '. ' + String(r.mortas).padStart(5) + ' mortas · ' + r.secao);
  console.log('       props: ' + r.props);
});
console.log('');
console.log('--- AMOSTRA DE 15 CONFLITOS CONCRETOS ---');
mortas.slice(0, 15).forEach(d => {
  console.log('  ' + d.seletor.slice(0, 70));
  console.log('     ' + d.prop + ': ' + d.valor.slice(0, 40) + '   [MORTA · ' + d.secao.slice(0, 34) + ']');
  console.log('     ' + d.prop + ': ' + d.valorFinal.slice(0, 40) + '   [VENCE · ' + d.secaoFinal.slice(0, 34) + ']');
});

if (jsonOut) {
  fs.writeFileSync(jsonOut, JSON.stringify({
    arquivo, tamanhoBytes: bruto.length, declaracoes: total,
    mortas: mortas.length, secoesAfetadas: ranking.length,
    ranking, amostra: mortas.slice(0, 300)
  }, null, 2));
  console.log('\n  JSON: ' + jsonOut);
}
