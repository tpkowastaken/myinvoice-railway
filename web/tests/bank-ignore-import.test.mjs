import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'
import { ref, computed } from 'vue'

function setup(upload) {
  const source = readFileSync(new URL('../src/pages/bank/StatementList.vue', import.meta.url), 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0]
  const script = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ESNext },
    transformers: { before: [context => node => ts.visitNode(node, function visit(n) { return ts.isImportDeclaration(n) ? undefined : ts.visitEachChild(n, visit, context) })] },
  }).outputText.replace(/export \{\};?/, '')
  return runInNewContext(`${script}\n({ allIgnoreSelected, toggleAllIgnoreSelected, uploadWithConfirmation, ignorePreview, ignoreSelected, confirmIgnorePreview, finishIgnorePreview, ambiguityModal, ambiguitySelected, confirmAmbiguity })`, {
    ref, computed, defineProps() {}, watch() {}, onMounted() {}, onBeforeUnmount() {},
    useRouter: () => ({}), useRoute: () => ({ query: {} }), useToast: () => ({}), useAuthStore: () => ({}),
    useI18n: () => ({ t: key => key, locale: ref('cs') }), bankApi: { upload, importPdf: upload },
  })
}
const preview = { response: { data: { error: { code: 'ignored_notices_confirmation', fingerprint: 'test', candidates: [{ index: 0, notice_id: 1 }] } } } }
const tick = () => new Promise(resolve => setImmediate(resolve))

test('asks before retry; passes selected pairs to PDF upload', async () => {
  const calls = []
  const page = setup(async (...args) => { calls.push(args); if (calls.length === 1) throw preview; return { ignored_transferred: 1 } })
  const pending = page.uploadWithConfirmation({ name: 'synthetic.pdf' })
  await tick()
  assert.equal(calls.length, 1)
  assert.equal(page.ignoreSelected.value.length, 0)
  page.ignoreSelected.value = [0]
  page.confirmIgnorePreview()
  const result = await pending
  assert.equal(result.ignored_transferred, 1)
  assert.equal(calls[1][2].fingerprint, 'test')
  assert.deepEqual(Array.from(calls[1][2].selected), [0])
})

test('cancel does not retry the upload', async () => {
  let calls = 0
  const page = setup(async () => { calls++; throw preview })
  const pending = page.uploadWithConfirmation({ name: 'synthetic.gpc' })
  await tick()
  page.finishIgnorePreview(null)
  assert.equal(await pending, null)
  assert.equal(calls, 1)
})

test('skip explicitly retries without transferring', async () => {
  let calls = 0
  const page = setup(async (_file, _account, decision) => { if (++calls === 1) throw preview; assert.equal(decision.skip, true); return {} })
  const pending = page.uploadWithConfirmation({ name: 'synthetic.gpc' })
  await tick()
  page.finishIgnorePreview({ skip: true })
  await pending
  assert.equal(calls, 2)
})

test('account choice is retained through confirmation and a changed preview resets selection', async () => {
  let calls = 0
  const page = setup(async (_file, account) => {
    calls++
    if (calls === 1) throw { response: { data: { error: { code: 'ambiguous_account_currency', candidates: [{ account_id: 7 }] } } } }
    assert.equal(account, 7)
    if (calls <= 3) throw preview
    return {}
  })
  const pending = page.uploadWithConfirmation({ name: 'synthetic.gpc' })
  await tick()
  page.ambiguitySelected.value = 7
  page.confirmAmbiguity()
  await tick()
  page.ignoreSelected.value = [0]
  page.confirmIgnorePreview()
  await tick()
  assert.equal(page.ignoreSelected.value.length, 0)
  page.finishIgnorePreview({ skip: true })
  await pending
  assert.equal(calls, 4)
})

test('select all uses candidate indexes and toggles back to no selection', () => {
  const page = setup(async () => ({}))
  page.ignorePreview.value = { candidates: [{ index: 2 }, { index: 5 }] }
  page.ignoreSelected.value = [2]
  assert.equal(page.allIgnoreSelected.value, false)
  page.toggleAllIgnoreSelected()
  assert.deepEqual(Array.from(page.ignoreSelected.value), [2, 5])
  assert.equal(page.allIgnoreSelected.value, true)
  page.toggleAllIgnoreSelected()
  assert.equal(page.ignoreSelected.value.length, 0)
  assert.equal(page.allIgnoreSelected.value, false)
})
