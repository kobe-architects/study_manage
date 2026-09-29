import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'
import { fileURLToPath, URL } from 'node:url'
import type { AtRule as PostcssAtRule, Node as PostcssNode, PluginCreator } from 'postcss'

/**
 * :hover のスタイルを @media (hover: hover) の中に移す PostCSS プラグイン。
 * iPad・スマホではタップした要素に :hover が残り続ける（行の背景色が変わったまま等）ため、
 * マウスで操作する端末でだけホバーの見た目を付ける。:focus など :hover 以外のセレクタはそのまま残す。
 */
const hoverOnlyWithMouse: PluginCreator<void> = () => ({
  postcssPlugin: 'hover-only-with-mouse',
  Rule(rule, { AtRule }) {
    if (!rule.selector.includes(':hover')) return
    for (let p: PostcssNode | undefined = rule.parent as PostcssNode | undefined; p; p = p.parent as PostcssNode | undefined) {
      if (p.type !== 'atrule') continue
      const at = p as PostcssAtRule
      if (/keyframes$/i.test(at.name) || /hover/.test(at.params)) return
    }
    const hover = rule.selectors.filter((s) => s.includes(':hover'))
    const rest = rule.selectors.filter((s) => !s.includes(':hover'))
    const media = new AtRule({ name: 'media', params: '(hover: hover)' })
    media.append(rule.clone({ selectors: hover }))
    rule.after(media)
    if (rest.length) rule.selectors = rest
    else rule.remove()
  },
})
hoverOnlyWithMouse.postcss = true

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), vuetify({ autoImport: true })],
  css: {
    postcss: {
      plugins: [hoverOnlyWithMouse()],
    },
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5180,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8090',
        changeOrigin: true,
      },
    },
  },
})
