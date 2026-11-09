/**
 * Testes da regra que evita escritas (e eventos SSE) desnecessários.
 * Execução: node --test tests/sync-service/pollHosts.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { needsUpdate } = require('../../sync-service/src/pollHosts.js');

const NOW = Date.parse('2026-11-09T10:00:00Z');
const recent = new Date(NOW - 60_000).toISOString();
const stale = new Date(NOW - 10 * 60_000).toISOString();
const REFRESH = 300_000;

test('mudança de status obriga sempre a escrever', () => {
    assert.equal(needsUpdate({ status: 'offline', last_seen: recent }, 'online', true, NOW, REFRESH), true);
    assert.equal(needsUpdate({ status: 'online', last_seen: recent }, 'offline', false, NOW, REFRESH), true);
});

test('host online sem alterações e last_seen recente NÃO escreve', () => {
    assert.equal(needsUpdate({ status: 'online', last_seen: recent }, 'online', true, NOW, REFRESH), false);
});

test('host offline sem alterações NÃO escreve', () => {
    assert.equal(needsUpdate({ status: 'offline', last_seen: recent }, 'offline', false, NOW, REFRESH), false);
});

test('last_seen antigo é renovado periodicamente', () => {
    assert.equal(needsUpdate({ status: 'online', last_seen: stale }, 'online', true, NOW, REFRESH), true);
});

test('host online sem last_seen é registado', () => {
    assert.equal(needsUpdate({ status: 'online', last_seen: null }, 'online', true, NOW, REFRESH), true);
});

test('data inválida é tratada como desconhecida (renova)', () => {
    assert.equal(needsUpdate({ status: 'online', last_seen: 'lixo' }, 'online', true, NOW, REFRESH), true);
});
