<script lang="ts">
/**
 * 質問事項のメッセージ本文（簡易タグ付き。lib/richText.ts）を描く。
 * HTML は解釈せず、解析した木を要素に組み立てるので安全。URL はリンクに、ファイル共有のページ指定リンクはアプリ内で開く。
 */
import { defineComponent, h, type VNode } from 'vue'
import { useRouter } from 'vue-router'
import { parseRich, richColor, type RichNode } from '@/lib/richText'

export default defineComponent({
  name: 'RichText',
  props: { text: { type: String, required: true } },
  setup(props) {
    const router = useRouter()
    const isTutor = () => localStorage.getItem('sm_role') === 'tutor'

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
                  router.push({ name: isTutor() ? 'tutor-files' : 'files', query: { file: String(shared.file), p: String(shared.page) } })
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
