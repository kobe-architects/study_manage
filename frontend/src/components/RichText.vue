<script lang="ts">
/**
 * 質問事項のメッセージ本文（簡易タグ付き。lib/richText.ts）を描く。
 * HTML は解釈せず、解析した木を要素に組み立てるので安全。URL はリンクに、ファイル共有のページ指定リンクはアプリ内で開く
 * （親が @shared を受けていればその場で開かせ、無ければファイル共有の画面へ移る）。[re=ID] の返信先は @jump で親に知らせる。
 */
import { defineComponent, getCurrentInstance, h, type VNode } from 'vue'
import { useRouter } from 'vue-router'
import { parseRich, richColor, type RichNode } from '@/lib/richText'

export default defineComponent({
  name: 'RichText',
  props: { text: { type: String, required: true } },
  emits: { shared: (_file: number, _page: number) => true, jump: (_messageId: number) => true },
  setup(props, { emit }) {
    const router = useRouter()
    const inst = getCurrentInstance()
    const isTutor = () => localStorage.getItem('sm_role') === 'tutor'
    const hasHandler = (name: string) => !!(inst?.vnode.props as Record<string, unknown> | null)?.[name]

    function render(nodes: RichNode[]): (VNode | string)[] {
      return nodes.map((n): VNode | string => {
        if (n.type === 'text') return n.text
        if (n.type === 'br') return h('br')
        if (n.type === 'link') {
          if (n.shared) {
            const shared = n.shared
            return h(
              'a',
              {
                class: 'rt-shared',
                href: n.href,
                onClick: (e: MouseEvent) => {
                  e.preventDefault()
                  if (hasHandler('onShared')) emit('shared', shared.file, shared.page)
                  else router.push({ name: isTutor() ? 'tutor-files' : 'files', query: { file: String(shared.file), p: String(shared.page) } })
                },
              },
              [
                h('svg', { width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [
                  h('path', { d: 'M6 3.5h9l4 4v13H6z' }),
                  h('path', { d: 'M15 3.5v4h4' }),
                ]),
                `ファイル共有（${shared.page} ページ）`,
              ],
            )
          }
          return h('a', { class: 'rt-link', href: n.href, target: '_blank', rel: 'noopener noreferrer' }, n.text)
        }
        const children = render(n.children)
        switch (n.tag) {
          case 'b':
            return h('strong', children)
          case 's':
            return h('s', children)
          case 'u':
            return h('u', children)
          case 'm':
            return h('mark', { class: 'rt-mark' }, children)
          case 'q':
            return h('blockquote', { class: 'rt-quote' }, children)
          case 're': {
            const id = Number(n.value)
            return h(
              'button',
              { type: 'button', class: 'rt-reply', title: '返信先のメッセージへ', onClick: () => emit('jump', id) },
              [
                h('svg', { width: 13, height: 13, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 2.2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [h('path', { d: 'M9 14L4 9l5-5' }), h('path', { d: 'M4 9h10a6 6 0 0 1 0 12h-3' })]),
                h('span', { class: 'rt-reply-text' }, children),
              ],
            )
          }
          case 'color':
            return h('span', { style: { color: richColor(n.value) ?? undefined } }, children)
        }
      })
    }

    return () => h('span', { class: 'rt' }, render(parseRich(props.text)))
  },
})
</script>

<style scoped>
.rt {
  white-space: pre-wrap;
  word-break: break-word;
  line-height: 1.6;
}
.rt-mark {
  background: #fff3a3;
  color: inherit;
  padding: 0 2px;
  border-radius: 3px;
}
.rt-link {
  color: var(--primary);
  text-decoration: underline;
  text-underline-offset: 2px;
  word-break: break-all;
}
.rt-quote {
  display: block;
  margin: 4px 0;
  padding: 4px 10px;
  border-left: 3px solid #c5cad2;
  color: #4b5563;
  background: #f6f7f9;
  border-radius: 0 8px 8px 0;
}
.rt-reply {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  width: 100%;
  margin: 0 0 6px;
  padding: 5px 10px;
  border: none;
  border-left: 3px solid var(--primary);
  border-radius: 0 8px 8px 0;
  background: #eef1fb;
  color: #3f4a6b;
  font: inherit;
  font-size: 12px;
  text-align: left;
  cursor: pointer;
  white-space: pre-wrap;
}
.rt-reply svg {
  flex-shrink: 0;
  margin-top: 2px;
  color: var(--primary);
}
.rt-reply-text {
  min-width: 0;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
}
.rt-shared {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 9px 2px 7px;
  margin: 1px 0;
  border-radius: 99px;
  background: #eef1fb;
  color: var(--primary);
  font-size: 12.5px;
  font-weight: 600;
  text-decoration: none;
  vertical-align: middle;
}
</style>
