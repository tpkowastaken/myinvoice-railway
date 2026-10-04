import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'
import { ref, computed, nextTick } from 'vue'

// Execute the page's setup with API/lifecycle doubles, retaining Vue reactivity.
function setup(ignore, unmatch, canWrite = true) {
  const source = readFileSync(new URL('../src/pages/bank/StatementDetail.vue', import.meta.url), 'utf8')
    .split('<script setup lang="ts">')[1].split('</script>')[0]
  const script = ts.transpileModule(source, {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
    transformers: { before: [context => node => ts.visitNode(node, function visit(n) {
      return ts.isImportDeclaration(n) ? undefined : ts.visitEachChild(n, visit, context)
    })] },
  }).outputText.replace(/export \{\};?/, '')
  return runInNewContext(`${script}\n({ textDetail, runDetailAction, openCreate, createTx, startMatch, matchCtx, matchingTx, matching, closeMatch, closeCreate, creatingPi, detailReturnTarget, statement, statusFilter, filteredTransactions, loading, ignoreTx, closeIgnore, confirmIgnore, ignoreTarget, ignoreNote, ignoreError, ignoring, unmatchTx, closeUnmatch, confirmUnmatch, unmatchTarget, unmatchError, unmatching })`, {
    ref, computed, nextTick, onMounted() {}, useHotkey() {},
    useRoute: () => ({ params: { id: 1 } }), useRouter: () => ({}),
    useAuthStore: () => ({ canWrite }), useToast: () => ({}),
    useI18n: () => ({ t: key => key, locale: ref('cs') }),
    apiErrorMessage: e => e.message,
    bankApi: { ignore, unmatch, matchCandidates: async () => ({ candidates: [], fallback: false }), get() { throw new Error('Unexpected page reload') } },
  })
}

function seed(page) {
  page.statement.value = { id: 1, transactions: [{ id: 2, match_status: 'unmatched' }] }
  page.loading.value = false
  page.statusFilter.value = 'unmatched'
  return page.statement.value.transactions[0]
}

test('cancel does not call API; confirmation updates the row and filter without reload', async () => {
  const calls = []
  const page = setup(async (id, note) => { calls.push([id, note]); return { ignored: true, ignore_note: note } })
  const tx = seed(page)
  page.ignoreTx(tx)
  page.closeIgnore()
  assert.equal(calls.length, 0)
  assert.equal(tx.match_status, 'unmatched')
  page.ignoreTx(tx)
  page.ignoreNote.value = '  Test note  '
  await page.confirmIgnore()
  assert.deepEqual(calls, [[2, 'Test note']])
  assert.equal(tx.match_status, 'ignored')
  assert.equal(tx.ignore_note, 'Test note')
  assert.equal(page.filteredTransactions.value.length, 0)
  assert.equal(page.statusFilter.value, 'unmatched')
  assert.equal(page.loading.value, false)
  assert.equal(page.ignoreTarget.value, null)
})

test('pending request prevents duplicate submission; failure preserves note and row for retry', async () => {
  let reject, calls = 0
  const page = setup(() => { calls++; return new Promise((_, no) => { reject = no }) })
  const tx = seed(page)
  page.ignoreTx(tx)
  page.ignoreNote.value = 'Retry note'
  const pending = page.confirmIgnore()
  await page.confirmIgnore()
  page.closeIgnore()
  assert.equal(calls, 1)
  assert.equal(page.ignoreTarget.value, tx)
  reject(new Error('Test failure'))
  await pending
  assert.equal(tx.match_status, 'unmatched')
  assert.equal(page.ignoreNote.value, 'Retry note')
  assert.equal(page.ignoreError.value, 'Test failure')
  assert.equal(page.ignoring.value, false)
  assert.equal(page.filteredTransactions.value.length, 1)
})


test('unmatch confirmation clears linked invoices and updates count without reload', async () => {
  let calls = 0
  const page = setup(null, async () => { calls++ })
  const tx = seed(page)
  Object.assign(tx, { match_status: 'manual', matched_invoice_id: 8, matched_invoices: [{ invoice_id: 8 }] })
  page.statement.value.matched_count = 1
  page.statusFilter.value = 'manual'
  page.unmatchTx(tx)
  page.closeUnmatch()
  assert.equal(calls, 0)
  assert.equal(tx.match_status, 'manual')
  page.unmatchTx(tx)
  await page.confirmUnmatch()
  assert.equal(calls, 1)
  assert.equal(tx.match_status, 'unmatched')
  assert.equal(tx.matched_invoice_id, null)
  assert.equal(tx.matched_invoices.length, 0)
  assert.equal(page.statement.value.matched_count, 0)
  assert.equal(page.filteredTransactions.value.length, 0)
  assert.equal(page.loading.value, false)
  assert.equal(page.unmatchTarget.value, null)
})

test('unmatch failure keeps dialog and original state; pending request cannot repeat', async () => {
  let reject, calls = 0
  const page = setup(null, () => { calls++; return new Promise((_, no) => { reject = no }) })
  const tx = seed(page)
  tx.match_status = 'manual'
  page.statement.value.matched_count = 1
  page.unmatchTx(tx)
  const pending = page.confirmUnmatch()
  await page.confirmUnmatch()
  page.closeUnmatch()
  assert.equal(calls, 1)
  assert.equal(page.unmatchTarget.value, tx)
  reject(new Error('Test failure'))
  await pending
  assert.equal(tx.match_status, 'manual')
  assert.equal(page.statement.value.matched_count, 1)
  assert.equal(page.unmatchError.value, 'Test failure')
  assert.equal(page.unmatching.value, false)
})

test('returning an ignored transaction keeps matched count and clears ignore note', async () => {
  const page = setup(null, async () => {})
  const tx = seed(page)
  Object.assign(tx, { match_status: 'ignored', ignore_note: 'Test note' })
  page.statement.value.matched_count = 3
  page.unmatchTx(tx)
  await page.confirmUnmatch()
  assert.equal(tx.match_status, 'unmatched')
  assert.equal(tx.ignore_note, null)
  assert.equal(page.statement.value.matched_count, 3)
})


test('detail actions close the detail before opening the existing dialog for the same transaction', async () => {
  const page = setup()
  const tx = seed(page)
  for (const [action, target] of [[page.openCreate, page.createTx], [page.startMatch, page.matchCtx], [page.ignoreTx, page.ignoreTarget], [page.unmatchTx, page.unmatchTarget]]) {
    page.textDetail.value = tx
    let called = false
    await page.runDetailAction(selected => {
      assert.equal(page.textDetail.value, null)
      assert.equal(selected, tx)
      called = true
      action(selected)
    })
    assert.equal(called, true)
    assert.equal(target.value, tx)
    target.value = null
  }
})

test('detail action cannot run twice or mutate from a read-only detail', async () => {
  const page = setup()
  page.textDetail.value = seed(page)
  let calls = 0
  const action = () => { calls++ }
  await Promise.all([page.runDetailAction(action), page.runDetailAction(action)])
  assert.equal(calls, 1)
  const readOnly = setup(null, null, false)
  readOnly.textDetail.value = seed(readOnly)
  await readOnly.runDetailAction(action)
  assert.equal(calls, 1)
  assert.notEqual(readOnly.textDetail.value, null)
})


test('cancelling every action opened from detail restores that same detail', async () => {
  const page = setup()
  const tx = seed(page)
  for (const [open, close] of [[page.startMatch, page.closeMatch], [page.openCreate, page.closeCreate], [page.ignoreTx, page.closeIgnore], [page.unmatchTx, page.closeUnmatch]]) {
    page.textDetail.value = tx
    await page.runDetailAction(open)
    close()
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(page.textDetail.value, tx)
    assert.equal(page.detailReturnTarget.value, null)
    page.textDetail.value = null
    open(tx)
    close()
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(page.textDetail.value, null)
  }
})

test('successful ignore or unmatch consumes return target; cancelling a later row action does not reopen it', async () => {
  const page = setup(async () => ({ ignore_note: null }), async () => {})
  const tx = seed(page)
  for (const [open, confirm, close] of [[page.ignoreTx, page.confirmIgnore, page.closeIgnore], [page.unmatchTx, page.confirmUnmatch, page.closeUnmatch]]) {
    page.textDetail.value = tx
    await page.runDetailAction(open)
    await confirm()
    assert.equal(page.textDetail.value, null)
    assert.equal(page.detailReturnTarget.value, null)
    open(tx)
    close()
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(page.textDetail.value, null)
  }
})

test('pending match and invoice creation cannot cancel back to detail', async () => {
  const page = setup()
  const tx = seed(page)
  for (const [open, close, busy] of [[page.startMatch, page.closeMatch, page.matching], [page.openCreate, page.closeCreate, page.creatingPi]]) {
    page.textDetail.value = tx
    await page.runDetailAction(open)
    busy.value = true
    close()
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(page.textDetail.value, null)
    assert.equal(page.detailReturnTarget.value, tx)
    busy.value = false
    close()
    await new Promise(resolve => setImmediate(resolve))
    assert.equal(page.textDetail.value, tx)
  }
})
