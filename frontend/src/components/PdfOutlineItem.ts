import { defineComponent, h, type PropType, type VNode } from 'vue'

/** PDF のブックマーク（アウトライン）の 1 項目。入れ子になる */
export interface PdfOutlineNode {
  title: string
  /** 行き先のページ番号（1 始まり）。解決できなければ null */
  page: number | null
  items: PdfOutlineNode[]
  open: boolean
}

/** 目次の 1 行の描画（再帰。コンポーネント自身を参照するため、型が循環しないように関数に分ける） */
function renderNode(props: { node: PdfOutlineNode; depth: number; current: number }, go: (page: number) => void): VNode {
  const n = props.node
  const row = h('div', { class: ['ol-row', { cur: n.page === props.current, nolink: n.page === null }], style: { paddingLeft: `${8 + props.depth * 14}px` } }, [
    n.items.length
      ? h(
          'button',
          {
            class: ['ol-tgl', { open: n.open }],
            type: 'button',
            'aria-label': n.open ? '閉じる' : '開く',
            onClick: (e: Event) => {
              e.stopPropagation()
              n.open = !n.open
            },
          },
          '▸',
        )
      : h('span', { class: 'ol-sp' }),
    h('button', { class: 'ol-title', type: 'button', disabled: n.page === null, onClick: () => n.page !== null && go(n.page) }, [
      h('span', { class: 'ol-t' }, n.title),
      n.page !== null ? h('span', { class: 'ol-p' }, String(n.page)) : null,
    ]),
  ])
  const kids: VNode[] = n.items.length && n.open ? n.items.map((c, i) => h(PdfOutlineItem, { key: i, node: c, depth: props.depth + 1, current: props.current, onGo: go })) : []
  return h('div', null, [row, ...kids])
}

/** 目次の 1 行（入れ子）。子があれば ▸ で開閉、タップでそのページへ（emit('go', page)） */
export const PdfOutlineItem = defineComponent({
  name: 'PdfOutlineItem',
  props: {
    node: { type: Object as PropType<PdfOutlineNode>, required: true },
    depth: { type: Number, required: true },
    current: { type: Number, required: true },
  },
  emits: ['go'],
  setup(props, { emit }) {
    return () => renderNode(props, (page: number) => emit('go', page))
  },
})
