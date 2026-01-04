# セッションサマリー

最終更新: 2026-01-04

## 最新の実装内容

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
- React
- Vite
- shadcn/ui（UIコンポーネント）
- Tailwind CSS

**開発環境**:
- Docker（PHP 8.3 + MySQL + phpMyAdmin）
- Composer

### ディレクトリ構成

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
docker-compose up

# PHP 依存関係インストール
composer install

# フロントエンドビルド
cd /home/user/openchat-alpha
npm run build

# Git コミット
git add .
git commit -m "message"
```

---

## 参考リンク

- [プロジェクトリポジトリ](https://github.com/pika-0203/Open-Chat-Graph)
- [shadcn/ui](https://ui.shadcn.com/)
- [Tailwind CSS](https://tailwindcss.com/)
