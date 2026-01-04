# セッションサマリー

最終更新: 2026-01-05（Preactグラフ最適化・キャッシュ活用）

## 開発環境の構成

このプロジェクトはPHPバックエンドとReactフロントエンドを組み合わせたハイブリッド構成です。

### ディレクトリ構造

```
# PHPバックエンド（メインプロジェクト）
/home/user/oc-review-dev/
├── app/                    # アプリケーションコード
│   ├── Controllers/       # API/ページコントローラー
│   ├── Models/            # データアクセス層
│   ├── Services/          # ビジネスロジック
│   └── Views/             # PHPテンプレート（alpha_content.php など）
├── public/                # Webサーバーのドキュメントルート
│   ├── index.php         # エントリーポイント
│   └── js/alpha/         # ビルド済みReactアプリ（ビルド時に生成）
│       ├── index.html
│       ├── index.js      # バンドル済みReact
│       └── index.css
└── docker-compose.yml    # 開発環境（PHP 8.3 + MySQL）

# Reactフロントエンド（別ディレクトリ）
/home/user/openchat-alpha/
├── src/                   # Reactソースコード
│   ├── pages/            # SearchPage, MyListPage, DetailPage など
│   ├── components/       # Reactコンポーネント
│   ├── api/              # APIクライアント（alpha.ts）
│   └── types/            # TypeScript型定義
├── vite.config.ts        # Vite設定（プロキシ、ビルド出力先）
└── package.json          # npm依存関係
```

### URL構成と開発環境の違い

#### 開発環境（Development）

**PHPサーバー（Docker）**:
- URL: `https://localhost:7433`
- ドキュメントルート: `/home/user/oc-review-dev/public/`
- Alphaページ: `https://localhost:7433/alpha`
  - PHPテンプレート（`app/Views/alpha_content.php`）がビルド済みReactを読み込む
  - `public/js/alpha/index.js`（バンドル済み）を実行
- APIエンドポイント:
  - `/alpha-api/search` → AlphaApiController
  - `/oc/{id}/position` → RankingPositionApiController
  - など

**Reactデベロップメントサーバー（Vite）**:
- URL: `http://localhost:5173`
- ソースコード: `/home/user/openchat-alpha/src/`
- ホットリロード: コード変更時に即座に反映
- **Viteプロキシ設定**（vite.config.ts）:
  ```typescript
  proxy: {
    '/alpha-api': { target: 'http://localhost:7000' },  // API リクエストをプロキシ
    '/oc': { target: 'http://localhost:7000' },         // グラフAPIをプロキシ
    '/js': { target: 'http://localhost:7000' },         // 静的ファイル（Preactグラフなど）をプロキシ
  }
  ```
- アクセス例:
  - `http://localhost:5173/` → 検索ページ（React開発サーバー）
  - `http://localhost:5173/alpha-api/search` → プロキシ経由で`localhost:7000/alpha-api/search`にアクセス
  - CORS問題を回避（同一オリジンとして扱われる）

#### 本番環境（Production）

- URL: `https://openchat-review.me`
- Alphaページ: `https://openchat-review.me/alpha`
- すべて同一オリジン → CORS問題なし
- ビルド済みファイル: `public/js/alpha/index.js`（Viteビルドで生成）

### ビルドプロセス

```bash
# 1. Reactアプリをビルド
cd /home/user/openchat-alpha
npm run build

# 2. ビルド出力先（vite.config.ts で設定）
# 出力: /home/user/oc-review-dev/public/js/alpha/
#   - index.html
#   - index.js (バンドル済み)
#   - index.css

# 3. PHPサーバーで確認
# https://localhost:7433/alpha にアクセス
# → alpha_content.php が public/js/alpha/index.js を読み込む
```

### 開発ワークフロー

1. **React開発時**:
   ```bash
   cd /home/user/openchat-alpha
   npm run dev  # localhost:5173 でホットリロード開発
   ```
   - コンポーネント、スタイル、ロジックの変更
   - Viteプロキシ経由でPHP APIにアクセス（CORS回避）

2. **ビルドと確認**:
   ```bash
   npm run build  # ビルド済みファイルを oc-review-dev/public/js/alpha/ に出力
   # https://localhost:7433/alpha で動作確認（本番環境に近い状態）
   ```

3. **コミット**:
   ```bash
   cd /home/user/oc-review-dev
   git add public/js/alpha/
   git commit -m "build: フロントエンド更新"
   ```

### 重要な設定ファイル

**vite.config.ts**:
```typescript
export default defineConfig({
  server: {
    port: 5173,
    proxy: {
      '/alpha-api': { target: 'http://localhost:7000', changeOrigin: true },
      '/oc': { target: 'http://localhost:7000', changeOrigin: true },
      '/js': { target: 'http://localhost:7000', changeOrigin: true },  // 追加
    },
  },
  build: {
    outDir: '../oc-review-dev/public/js/alpha',  // ビルド出力先
  },
  base: mode === 'production' ? '/js/alpha/' : '/',  // 本番ビルド時のベースパス
})
```

**App.tsx**:
```typescript
function App() {
  // 開発: '/', 本番: '/alpha'
  const basename = import.meta.env.DEV ? '/' : '/alpha'
  return <BrowserRouter basename={basename}>...</BrowserRouter>
}
```

---

## 最新の実装内容

### SVGアイコンのReactコンポーネント化とCORS対応（2026-01-04）

#### SVGアイコンの埋め込み（f5d44f9, d8830b4）

**課題**: 外部SVGファイル（`/assets/official.svg`, `/assets/special.svg`）への参照が不安定

**解決策**: SVGをReactコンポーネントとして埋め込み
- `src/components/icons/OfficialIcon.tsx` - 公式認証バッジ
- `src/components/icons/SpecialIcon.tsx` - スペシャルバッジ
- `src/components/icons/index.ts` - バレルエクスポート

**使用箇所**:
- `DetailInfo.tsx` - 詳細ページのタイトル
- `OpenChatCard.tsx` - 検索結果・マイリストのカード

**メリット**:
- バンドルに埋め込まれるため確実にロード
- 外部ファイル参照が不要
- Tree-shakingで未使用アイコンを削除可能

#### CORS問題の解決（b53aaf2, d8830b4）

**課題**: 開発環境で`localhost:5173`から`localhost:7000`のAPI（`/oc/{id}/position_hour`など）にアクセスするとCORSエラー

**解決策**: Viteプロキシ設定を追加

**vite.config.ts**:
```typescript
server: {
  proxy: {
    '/alpha-api': { target: 'http://localhost:7000', changeOrigin: true },
    '/oc': { target: 'http://localhost:7000', changeOrigin: true },  // 追加
  },
}
```

**alpha.ts**:
```typescript
// 開発環境: /oc/{id}/position_hour → Viteプロキシ → localhost:7000/oc/{id}/position_hour
// 本番環境: /oc/{id}/position_hour → 同一オリジンなのでCORS問題なし
async getRankingPositionHour(openChatId: number, category?: number, sort?: string): Promise<any> {
  const res = await fetch(`/oc/${openChatId}/position_hour${queryString}`)
  return res.json()
}
```

**重要**: Vite開発サーバーの再起動が必要
```bash
cd /home/user/openchat-alpha
npm run dev  # Ctrl+C で停止後、再起動
```

---

### React 19.2への移行とソート機能の大幅改善（2026-01-04）

#### React 19.2のActivityコンポーネント導入
- **ページ切り替えロジックのリファクタリング**（25d798f）:
  - React 19.2の新しいActivityコンポーネントを使用
  - ページ遷移のパフォーマンスと安定性を向上

#### ツールバーとソート機能の改善
- **タイトルバーにソート方法を表示**（9cad7a9）:
  - 現在のソート状態をタイトルバーに表示
  - 件数表示のマージンを調整してレイアウト改善
- **検索ボタンをツールバーのソートボタンに置き換え**（ff352e3）:
  - UIをよりシンプルに整理
  - ソート機能へのアクセスを改善
- **ドロップダウンメニューのモバイル対応とカーソル改善**（820794a）:
  - タッチデバイスでの操作性を向上
  - カーソルスタイルを改善

#### マイリストの並び替え機能実装
- **統計値での並び替え機能を追加**（fbae2f6）:
  - メンバー数、増減率などの統計値でソート可能に
  - ランキング非掲載時のソート処理に対応
- **ソート対象の視覚的フィードバック改善**（fb79e33, 484cee4, d376129）:
  - ソート時に対応する統計値を先頭に表示
  - ソート対象のカラムとラベルを太字で強調表示
- **統計値の表示改善**（866f4e0, bc7b8c4）:
  - ±0の値を控えめな色（text-muted-foreground）で表示
  - 1週間統計のN/A判定ロジックを修正

#### 検索機能の改善
- **同じキーワードでの再検索機能**（bec20a4, 3b1ca0e）:
  - refreshKey方式で実装
  - 同じ検索クエリでも再検索可能に
- **検索ハイライトの改善**（7c86ea6）:
  - キーワード位置に関係なく検索ハイライトを表示
- **カードデザインの改善**（6ccc7c2）:
  - 検索結果とマイリストのカードUIを改善

#### レイアウトとスペーシングの調整
- **MyListPageのレイアウト改善**（8488cef, 4eacd6a）:
  - ツールバーとコンテンツ間の空白を修正
  - コンテンツエリアにトップパディングを追加
- **詳細画面のバッジ表示改善**（7466235）:
  - ランキング非掲載バッジを先頭に表示

#### 開発環境とテスト
- **basename統一**（01aad1a）:
  - 開発環境と本番環境でbasenameとマウントポイントを統一
  - 環境間の一貫性を向上
- **e2eテスト追加**（1697960）:
  - MyListPageのツールバーとコンテンツ間隔のe2eテストを追加
- **ドキュメント改善**（2eecbf6）:
  - CLAUDE.mdにサブエージェント活用のベストプラクティスを追加

---

### ランキング掲載履歴機能（2026-01-04）

詳細ページにランキング非掲載履歴を表示する機能を実装しました。

#### 実装内容

**バックエンド**:
- API エンドポイント: `GET /alpha-api/ranking-history/{open_chat_id}`
- リポジトリメソッド: `RankingBanPageRepository::findHistoryByOpenChatId()`
- データフィルタリング:
  - 直近1週間以内のレコードは全て表示
  - 1週間より前で現在も未掲載中のレコードは表示
  - 1週間より前で非掲載期間が1時間超のレコードは表示
  - 1週間より前で非掲載期間が1時間以下のレコードは除外（ノイズ削減）

**フロントエンド**:
- コンポーネント: `RankingHistory.tsx`
- shadcn/ui コンポーネントの追加:
  - `separator.tsx` - 区切り線
  - `alert.tsx` - アラートボックス
- モダンなUIデザイン:
  - Badge（掲載状況）
  - Alert（非掲載時点の状況）
  - Icons（Clock, Users, TrendingUp/Down, BarChart3, FileText）

**時間表示ロジック**:
- 72時間以下: 時間表記（例: `48時間`, `1時間`, `30分`）
- 72時間超: 日数表記（例: `5日`, `10日`）
  - 時間部分は表示しない（シンプル化）
- ラベル:
  - 現在未掲載: `現在未掲載中: 〜時間`
  - 再掲載済み: `非掲載だった時間: 〜時間`

**レイアウト**:
- 配置順: DetailInfo → DetailStats → Graph → DetailActions → RankingHistory
- DetailActions の下に 1rem マージン追加
- スマホでスペーシングを最適化（`-mt-2 md:mt-0`）

**UIの詳細**:
- ランキング順位のラベル: `ランキング順位（同一カテゴリ内）`
- 非掲載時点の状況:
  - メンバー数（その時点 vs 現在、差分）
  - ランキング順位（パーセンテージ）
- 変更内容の表示（日本語翻訳）

#### 関連ファイル

**バックエンド**:
- `app/Config/routing.php` - ルート追加
- `app/Controllers/Api/AlphaApiController.php` - rankingHistory() メソッド
- `app/Models/RankingBanRepositories/RankingBanPageRepository.php` - findHistoryByOpenChatId() メソッド

**フロントエンド**:
- `~/openchat-alpha/src/components/Detail/RankingHistory.tsx` - メインコンポーネント
- `~/openchat-alpha/src/components/ui/separator.tsx` - 新規作成
- `~/openchat-alpha/src/components/ui/alert.tsx` - 新規作成
- `~/openchat-alpha/src/pages/DetailPage.tsx` - 統合
- `~/openchat-alpha/src/types/api.ts` - 型定義追加
- `~/openchat-alpha/src/api/alpha.ts` - API クライアント

#### コミット履歴

```
fee38b6d - feat: ランキング掲載履歴機能を追加（モダンUI、マージン調整）
fa397566 - fix: 時間表示ロジックを改善（72時間以下は時間表記、ラベル明確化）
265dc3dd - refactor: 1週間より前の短時間非掲載レコードを除外
6c1efa1c - fix: DetailActionsを掲載履歴の上に移動、スマホでスペース調整
f4e46ee3 - fix: DetailActionsの上下マージンを削減
888b2d16 - fix: DetailActionsに下ボーダーとマージンを追加
3dc25aa5 - fix: DetailActionsのボーダーを削除、マージンのみに変更
b2ed8588 - fix: ランキング順位のラベルを明確化
5bfabcb4 - fix: 日数表示時に時間部分を非表示に変更
```

---

### APIレスポンス型修正とViteプロキシ設定拡張（2026-01-05）

#### 課題と背景

グラフのReact移植を取り消した際の残骸により、DetailPageでTypeErrorが発生：
```
DetailStats.tsx:80 Uncaught TypeError: Cannot read properties of undefined (reading 'toLocaleString')
```

また、開発環境（localhost:5173）でPreactグラフスクリプト（`/js/preact-chart/assets/index.js`）にアクセスできない問題が発生。

#### 解決策

**1. APIレスポンスのフィールド名統一（7de37463）**

`AlphaApiController::stats()` のレスポンスフィールド名を TypeScript 型定義（`BasicInfoResponse`）に合わせて修正：

- `member` → `currentMember`
- `desc` → `description`
- `img` → `thumbnail`
- `increasedMember` → `hourlyDiff`
- `percentageIncrease` → `hourlyPercentage`
- `join_method_type` → `joinMethodType`

これにより、DetailPageでの `currentMember.toLocaleString()` エラーを解消。

**2. Viteプロキシ設定に `/js` パスを追加（7319d04）**

```typescript
// vite.config.ts
proxy: {
  '/alpha-api': { target: 'http://localhost:7000', changeOrigin: true },
  '/oc': { target: 'http://localhost:7000', changeOrigin: true },
  '/js': { target: 'http://localhost:7000', changeOrigin: true },  // 追加
}
```

これにより、開発環境で Preactグラフスクリプトにアクセス可能に。

#### 影響範囲

- **PHP側**: `app/Controllers/Api/AlphaApiController.php`
- **React側**: `vite.config.ts`
- **ビルド**: `public/js/alpha/index.js` を更新

#### 関連コミット

```
7de37463 - fix: APIレスポンスのフィールド名をTypeScript型定義に統一
7319d04 - feat: e2eテスト高速版・網羅版分割とViteプロキシ設定追加
4ff4ba4c - build: フロントエンド更新（APIレスポンス型修正反映）
```

---

### e2eテスト最適化と高速版・網羅版分割（2026-01-05）

#### 課題

e2eテストの実行時間が長く、フロントエンド変更時に毎回すべてのテストを実行するのは非効率。また、重複したテストファイルが存在。

#### 解決策

**1. 重複・不要テストの削除**

- `debug-mylist.spec.ts` - デバッグ専用テスト（削除）
- `navigation-search-state.spec.ts` - `core-navigation.spec.ts` と重複（削除）

**2. Playwright設定の更新（playwright.config.ts）**

テストを「高速版（fast）」と「網羅版（full）」の2つのプロジェクトに分割：

```typescript
projects: [
  // 高速版：フロントエンド変更時に常に実行する重要テスト（5分以内）
  {
    name: 'fast',
    testMatch: [
      '**/core-navigation.spec.ts',
      '**/critical-ux.spec.ts',
      '**/detail-page-buttons.spec.ts',
      '**/layout-responsiveness.spec.ts',
      '**/page-rerender-on-reclick.spec.ts',
    ],
    use: { ...devices['Desktop Chrome'], headless: true },
  },
  // 網羅版：包括的なテスト（すべてのテスト）
  {
    name: 'full',
    testMatch: '**/*.spec.ts',
    use: { ...devices['Desktop Chrome'], headless: true },
  },
],
```

**3. テストスクリプト追加（package.json）**

```json
"scripts": {
  "test": "playwright test --project=fast",
  "test:fast": "playwright test --project=fast",
  "test:full": "playwright test --project=full",
  "test:ui": "playwright test --ui"
}
```

**4. ドキュメント作成**

`TESTING.md` を追加：
- 各テストの種類と目的
- テスト実行コマンド
- ベストプラクティス
- CI/CD統合方法

#### テスト分類

**高速版（fast）** - フロントエンド変更時に常に実行（目安：5分以内）:
- `core-navigation.spec.ts` - コアナビゲーションパターン
- `critical-ux.spec.ts` - 重要なUX（検索とナビゲーション）
- `detail-page-buttons.spec.ts` - 詳細ページのボタン動作
- `layout-responsiveness.spec.ts` - レイアウトレスポンシブ対応
- `page-rerender-on-reclick.spec.ts` - ページ再レンダリング

**網羅版（full）** - すべてのe2eテスト（包括的）:
- 上記の高速版テストに加えて
- `browser-navigation.spec.ts`
- `detail-page-navigation.spec.ts`
- `graph-display-repeated-navigation.spec.ts`
- `mylist-file-explorer.spec.ts`
- `mylist-folder-url-navigation.spec.ts`
- `mylist-toolbar-spacing.spec.ts`
- `navigation-and-buttons.spec.ts`
- `performance-check.spec.ts`
- `scroll-persistence.spec.ts`
- `scroll-restoration.spec.ts`

#### ヘッドレスモード確認

すべてのテストが `headless: true` でヘッドレスモード（ブラウザUIなし）で実行されることを確認。CI/CD環境やバックグラウンドでの高速実行が可能。

#### 開発ワークフロー

```bash
# フロントエンド変更時（高速版）
npm run test:fast

# コミット前（網羅版）
npm run test:full

# デバッグ時
npm run test:ui
```

#### 関連ファイル

- `playwright.config.ts` - プロジェクト設定
- `package.json` - テストスクリプト
- `TESTING.md` - テスト実行ガイド
- `e2e/` - テストファイル（15個、2個削除）

---

### Preactグラフの最適化とキャッシュ活用（2026-01-05）

#### 課題

1. **データラベルの重なり問題**: 棒グラフ下部のデータラベル（順位数字）が重なって表示される
2. **繰り返しナビゲーションでグラフが表示されない**: 詳細ページを何度も遷移するとグラフが表示されなくなる
3. **毎回JSをダウンロード**: cache bustingによりブラウザキャッシュが効かず、ページ遷移のたびにJSをダウンロードして遅い

#### 解決策

**1. データラベル重なり問題の修正（6c3535d）**

`buildPlugin.ts`のdatalabels設定から`padding: 0`を削除：

- `padding: 0`が設定されていると、chartjs-plugin-datalabelsの衝突検出アルゴリズムが正しく動作しない
- プラグインのデフォルトパディングを使用することで、ラベルの衝突検出が正常に動作
- `getDataLabelBarCallback.ts`の`'auto'`表示設定が機能し、データラベルが重ならないように自動的に間引き表示される

**2. グローバルマウント/アンマウント関数の実装（c49d221, c0a7d4b）**

従来のアプローチ（毎回スクリプトをロード）から、グローバル関数を使用したアプローチに変更：

**oc-review-graph側の変更**:
```typescript
// main.tsx - グローバル関数を公開
function mountPreactChart() {
  updateThemeFromDOM()
  appContainer = document.getElementById('app')
  appContainer.innerHTML = ''
  render(<App />, appContainer)
}

function unmountPreactChart() {
  if (appContainer) {
    render(null, appContainer)
    appContainer.innerHTML = ''
  }
  if (chart.chart) {
    chart.chart.destroy()
  }
  resetChartState()
}

window.mountPreactChart = mountPreactChart
window.unmountPreactChart = unmountPreactChart
```

**openchat-alpha側の変更**:
```typescript
// DetailPage.tsx - 初回のみスクリプトをロード
useEffect(() => {
  const existingScript = document.getElementById('preact-chart-script')
  if (existingScript) return

  const script = document.createElement('script')
  script.id = 'preact-chart-script'
  script.src = '/js/preact-chart/assets/index.js'  // cache busting なし
  document.head.appendChild(script)
}, [])

// グローバルマウント関数を呼び出し
useEffect(() => {
  if (!basicInfo) return

  // chart-arg, theme-config をDOMに注入
  // ...

  // マウント関数を呼び出し
  const waitForMount = setInterval(() => {
    if (window.mountPreactChart) {
      clearInterval(waitForMount)
      window.mountPreactChart()
    }
  }, 50)

  return () => {
    clearInterval(waitForMount)
    if (window.unmountPreactChart) {
      window.unmountPreactChart()
    }
  }
}, [id, basicInfo, resolvedTheme])
```

**メリット**:
- ✅ スクリプトは初回のみロードされ、ブラウザキャッシュを活用
- ✅ 繰り返しナビゲーション時も正しく再マウント
- ✅ JSダウンロードは初回のみで、2回目以降は高速表示
- ✅ 状態管理がクリーンアップされ、メモリリークを防止

**3. フェードインアニメーションの削除**

グラフ表示時のopacityトランジション（0→1のフェードイン）を削除：

```typescript
// 削除前
<div id="graph-box" style={{
  opacity: 0,
  transition: 'opacity 0.6s ease 0s'
}} />

// 削除後
<div id="graph-box" style={{
  // opacity設定なし（デフォルト=1）
}} />
```

即座に表示されるようになり、UXが向上。

**4. e2eテストの修正（7cca9f8）**

フェードイン削除に伴い、opacityチェックを修正：

```typescript
// 修正前
expect(graphOpacity).toBe('1')

// 修正後（opacityが空文字列でもOK）
expect(graphOpacity !== 'not found', 'graph-boxが存在するべき').toBeTruthy()
```

全テストが成功（3/3 passed）。

**5. 初回自動マウントロジックの削除（d20fd77）**

`main.tsx`の初回自動マウントロジックは、スクリプトロード時に`#app`要素が存在しない場合に動作しない問題があった：

```typescript
// 削除: 初回自動マウント
if (document.getElementById('app')) {
  mountPreactChart()
}
```

DetailPageから明示的に`mountPreactChart()`を呼び出す設計に統一し、確実にマウントされるように修正。

#### テスト結果

```bash
npm run test -- e2e/graph-display-repeated-navigation.spec.ts --project=full

# 結果
✓ 5回の詳細ページ遷移でグラフが毎回正しく表示される
✓ 詳細ページから別の詳細ページに直接遷移してもグラフが表示される
✓ 同じ詳細ページに再訪問してもグラフが表示される

3 passed (18.6s)
```

すべてのイテレーションで：
- Preactスクリプトは1個のみ（重複なし）
- Preactアプリが正しくマウントされている（appContent > 0）
- グラフが毎回表示される

#### 影響範囲

**oc-review-graph**:
- `src/main.tsx` - グローバル関数の公開
- `src/app.tsx` - フェードイン削除
- `src/signal/chartState.ts` - リセット関数追加
- `src/classes/ChartJS/Factories/buildPlugin.ts` - padding削除

**openchat-alpha**:
- `src/pages/DetailPage.tsx` - グローバル関数の使用、フェードイン削除
- `e2e/graph-display-repeated-navigation.spec.ts` - テスト修正

#### コミット履歴

**oc-review-graph** (feature/dark-mode):
```
6c3535d - fix: データラベルの重なり問題を修正（padding設定を削除）
c49d221 - feat: グローバルマウント/アンマウント関数を実装
d20fd77 - fix: 初回自動マウントロジックを削除
```

**openchat-alpha** (dev):
```
c0a7d4b - feat: Preactチャートのグローバルマウント関数に対応
7cca9f8 - fix: グラフ表示テストをフェードイン削除に対応
```

---

## プロジェクト概要

### 基本情報
- **プロジェクト名**: オプチャグラフ (OpenChat Graph)
- **URL**: https://openchat-review.me
- **言語**: 日本語（主）、タイ語、繁体字中国語
- **ライセンス**: MIT

### 技術スタック

**バックエンド**:
- PHP 8.3
- MySQL/MariaDB（メインデータ）
- SQLite（ランキング・統計データ）
- MimimalCMS（カスタムMVCフレームワーク）

**フロントエンド**:
- TypeScript
- React 19.2
- Vite 7.3
- shadcn/ui（UIコンポーネント）
- Tailwind CSS

**開発環境**:
- Docker（PHP 8.3 + MySQL + phpMyAdmin）
- Composer

### アプリケーションディレクトリ構成

```
/app/                 - メインアプリケーション
  Config/            - ルーティング、設定
  Controllers/       - HTTPハンドラ（Api/, Page/）
  Models/            - データアクセス層（Repositories）
  Services/          - ビジネスロジック
  Views/             - テンプレート
/batch/              - バックグラウンド処理
  cron/              - スケジュールタスク
  exec/              - CLI実行ファイル
  sh/                - シェルスクリプト
/shadow/             - MimimalCMSフレームワーク
/shared/             - フレームワーク設定、DI
/storage/            - 多言語データ、SQLiteデータベース
```

### 開発パターン

**依存性注入**:
- `/shared/MimimalCmsConfig.php` でインターフェースと実装をマッピング
- リポジトリパターン
- コンストラクタインジェクション

**データベースアクセス**:
- `App\Models\Repositories\DB` クラスを使用
- 複雑なクエリには生SQLを使用
- パフォーマンス最適化のための手動チューニング

**フロントエンド統合**:
- React コンポーネントを PHP テンプレートに埋め込み
- ビルド済み JavaScript バンドル
- クライアントサイドレンダリング

---

## 開発ガイドライン

### コミットメッセージ

```
<type>: <subject>

<body>

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>
```

**Type**:
- `feat`: 新機能
- `fix`: バグ修正
- `docs`: ドキュメント変更
- `refactor`: リファクタリング
- `test`: テスト追加・修正
- `build`: ビルド関連
- `chore`: ビルド、補助ツールの変更

### タスク実行のベストプラクティス

1. **Plan Agent の使用**: 複雑なタスクは Plan Agent で事前計画
2. **頻繁なコミット**: 論理的な単位ごとにコミット
3. **Playwright テスト**: 実装後にブラウザテストで検証
4. **レスポンシブ対応**: モバイル・デスクトップ両方で確認

### コードスタイル

**PHP**:
- PSR-4 オートローディング
- 型宣言を使用（`declare(strict_types=1)`）
- リポジトリパターン

**TypeScript/React**:
- 関数コンポーネント + Hooks
- `memo` で最適化
- shadcn/ui コンポーネントの活用

---

## よく使うコマンド

```bash
# Docker 起動
cd /home/user/oc-review-dev
docker-compose up

# PHP 依存関係インストール
composer install

# React開発サーバー起動（ホットリロード）
cd /home/user/openchat-alpha
npm run dev
# → http://localhost:5173

# フロントエンドビルド（本番確認用）
cd /home/user/openchat-alpha
npm run build
# → ビルド出力: /home/user/oc-review-dev/public/js/alpha/
# → 確認: https://localhost:7433/alpha

# e2eテスト
cd /home/user/openchat-alpha
npm run test:fast  # 高速版（デフォルト）
npm run test:full  # 網羅版
npm run test:ui    # UIモード（デバッグ用）

# Git コミット
cd /home/user/oc-review-dev
git add .
git commit -m "message"
```

---

## 参考リンク

- [プロジェクトリポジトリ](https://github.com/pika-0203/Open-Chat-Graph)
- [shadcn/ui](https://ui.shadcn.com/)
- [Tailwind CSS](https://tailwindcss.com/)
- [Vite](https://vite.dev/)
