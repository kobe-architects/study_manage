import client from '@/api/client'
import type { SharedBrowse, SharedFile, SharedFolder } from '@/types'

/** API プレフィックス。講師ログイン時は /tutor 配下（担当生徒のファイルを同じように読み書きする） */
function p(): string {
  return localStorage.getItem('sm_role') === 'tutor' ? '/tutor' : ''
}

const CHUNK_SIZE = 4 * 1024 * 1024 // 4MB（共有サーバーの post_max_size を超えないサイズ）

/** ファイル共有（Google Drive 風の階層フォルダ）の API */
export const sharedApi = {
  prefix: p,

  async browse(folderId: number | null): Promise<SharedBrowse> {
    const { data } = await client.get(`${p()}/shared/browse`, { params: folderId ? { folder: folderId } : {} })
    return data.data
  },

  /** 全フォルダ（移動先の選択用。親 ID を持つ平らな配列） */
  async folders(): Promise<{ id: number; parentId: number | null; name: string }[]> {
    const { data } = await client.get(`${p()}/shared/folders`)
    return data.data
  },

  async search(q: string): Promise<{ folders: SharedFolder[]; files: SharedFile[] }> {
    const { data } = await client.get(`${p()}/shared/search`, { params: { q } })
    return data.data
  },

  async createFolder(parentId: number | null, name: string): Promise<SharedFolder> {
    const { data } = await client.post(`${p()}/shared/folders`, { parentId, name })
    return data.data
  },

  /** フォルダ階層をまとめて用意（無い階層だけ作る）。末端のフォルダ ID を返す。フォルダごとアップロード用 */
  async ensureFolders(parentId: number | null, path: string[]): Promise<number> {
    const { data } = await client.post(`${p()}/shared/folders/ensure`, { parentId, path })
    return data.data.folderId as number
  },

  async updateFolder(id: number, patch: { name?: string; parentId?: number | null }): Promise<SharedFolder> {
    const { data } = await client.put(`${p()}/shared/folders/${id}`, patch)
    return data.data
  },

  async deleteFolder(id: number): Promise<void> {
    await client.delete(`${p()}/shared/folders/${id}`)
  },

  /**
   * 分割アップロード → 確定。onProgress は 0..1。
   * searchText は画面側（pdf.js）で抜き出した PDF の文字（本文検索用。任意）。
   * signal で途中で止められる（止めたら AbortError）。
   */
  async upload(
    folderId: number | null,
    file: File,
    extra: { searchText?: string | null; pageCount?: number | null } = {},
    onProgress?: (ratio: number) => void,
    signal?: AbortSignal,
  ): Promise<SharedFile> {
    const uploadId = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`
    const total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE))
    for (let i = 0; i < total; i++) {
      signal?.throwIfAborted()
      const fd = new FormData()
      fd.append('uploadId', uploadId)
      fd.append('index', String(i))
      fd.append('total', String(total))
      fd.append('chunk', file.slice(i * CHUNK_SIZE, Math.min(file.size, (i + 1) * CHUNK_SIZE)), 'chunk.bin')
      await client.post(`${p()}/shared/files/chunk`, fd, { signal })
      onProgress?.((i + 1) / total)
    }
    signal?.throwIfAborted()
    const { data } = await client.post(`${p()}/shared/files`, {
      uploadId,
      folderId,
      name: file.name,
      mime: file.type || null,
      searchText: extra.searchText ?? null,
      pageCount: extra.pageCount ?? null,
    })
    return data.data
  },

  async updateFile(id: number, patch: { name?: string; folderId?: number | null; note?: string | null }): Promise<SharedFile> {
    const { data } = await client.put(`${p()}/shared/files/${id}`, patch)
    return data.data
  },

  async deleteFile(id: number): Promise<void> {
    await client.delete(`${p()}/shared/files/${id}`)
  },

  /** ファイル本体の URL（要 Bearer。pdf.js は httpHeaders で、画像は AuthImage で） */
  fileUrl(id: number): string {
    return `/api${p()}/shared/files/${id}/file`
  },

  /** 認証付きで取得して Blob にする（ダウンロード・画像表示用） */
  async fetchBlob(id: number): Promise<Blob> {
    const res = await client.get(`${p()}/shared/files/${id}/file`, { responseType: 'blob' })
    return res.data
  },
}

/** 表示用: バイト数を読みやすく */
export function fmtBytes(n: number): string {
  if (n < 1024) return `${n} B`
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`
  if (n < 1024 * 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`
  return `${(n / 1024 / 1024 / 1024).toFixed(2)} GB`
}
