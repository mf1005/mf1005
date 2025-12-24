# Portfolio: High-Performance UGC Platform

## ■ プロジェクト概要
個人開発として運用中のユーザー投稿型プラットフォーム。
レンタルサーバー（Mixhost）の制約下で、月間130万PV・同時接続数スパイクに対応するパフォーマンスチューニングを実施。

*   **月間PV:** 1,200,000 PV
*   **収益:** 月約 20万円
*   **インフラ:** Mixhost (Shared Hosting)
*   **技術スタック:** PHP (Laravel), MySQL, Cron, Linux

**■ＰＶ数**  
<img width="1562" height="617" alt="image" src="https://github.com/user-attachments/assets/ac9e4ad8-de9a-41a1-ab95-5f2ddf6e39cd" />  
**■収益**  
<img width="529" height="245" alt="image" src="https://github.com/user-attachments/assets/ba847131-9d9d-4de3-b64c-b2874bf765a3" />  

---

## ■ 技術的ハイライト：レンタルサーバーでの限界突破

### 1. 課題：キャッシュ・スタンピードによるサーバー停止危機
サービス急拡大時、動的生成（Laravel）とDB負荷が限界を超え、ホスティング会社より**アカウント停止警告**を受領。

**▼ 当時の警告メール（証拠）**
<img width="1456" height="712" alt="image" src="https://github.com/user-attachments/assets/9efdaa16-5853-40c9-9255-fecb4a1da58b" />
> 文言: 「サーバー全体への影響が懸念された」→他ユーザに実害が出ている  
> 文言: 「パフォーマンスの制限をかけた」→制限をかけたという事後報告で実質的なペナルティ  
> 数値: Query_time: 10.048353 →1クエリに10秒かかっている絶望感  
> 数値: Rows_examined: 670470 →大量データの取得  
> SQL文: select max(Opponent_User_ID)... →ユーザーアクセスをトリガーとするキャッシュ生成処理の一部  

### 2. 解決策：Read/Writeの完全分離アーキテクチャ
安易なクラウド移行（課金によるスケールアップ）はROI（投資対効果）が低いと判断し、コードと設計による解決を選択。  
従来の「ユーザーアクセスをトリガーとするキャッシュ生成」を廃止し、<strong>「バッチによる完全非同期・先行生成」</strong>と<strong>「イベント駆動の差分更新」</strong>へ刷新することで、参照系のDB負荷を極限まで減少。  

**■システム構成図**  
```mermaid
graph LR
 %% ノード定義
 User["User (Browser)"]
 App["PHP App<br>(Controller)"]
 Cache["Cache Objects<br>(UserID-Keyed JSON/Array)"]
 
 subgraph Storage ["Data Management"]
 direction TB
 DB[("(Master DB)")]
 Cron["Nightly Batch (Cron)"]
 end

 %% 1. 読み込み (Read) - DBを完全に回避
 User == "1. Access with UserID" ==> App
 App == "2. Key-Value Lookup" ==> Cache
 Cache == "3. Object Return" ==> App
 App == "4. Response" ==> User

 %% 2. 書き込み (Write) - DB更新とキャッシュへの即時反映
 User -- "Post/Update" --> App
 App -- "Update Master" --> DB
 App -.-> |"Incremental Update"| Cache

 %% 3. バッチ更新 (Batch) - 重い集計の肩代わり
 Cron -- "Heavy Aggregate Query" --> DB
 DB -- "Summarized Data" --> Cron
 Cron -- "Rebuild Bulk Cache" --> Cache

 style Cache fill:#f9f,stroke:#333,stroke-width:4px
 style DB fill:#ff9,stroke:#333,stroke-width:2px
```

### 3. 課題と対策：データの整合性担保
上記の構成により負荷は解消したが、バッチ実行中（数分間）のデータ更新による<strong>先祖返り（ロストアップデート）</strong>のリスクが発生。  
これ防ぐため、以下の**差分マージフロー**を実装し、データの整合性を担保。  

**▼ 整合性フロー図**
```mermaid
sequenceDiagram
    participant Time as Time Axis
    participant User as User Action
    participant Flag as Lock/Flag
    participant Cron as Cron Batch
    participant DB as Master DB
    participant Cache as Cache File

    Note over Time: ① 夜間バッチ開始
    Cron->>Flag: ② 開始フラグ ON (Flag=1)
    
    rect rgb(240, 240, 240)
        Note right of Time: ▼ バッチ実行期間 (Critical Section) ▼
        
        par Parallel Process
            Cron->>DB: ③ 全件集計・キャッシュ生成開始
            
            Note right of User: この間にユーザーが投稿！
            User->>DB: INSERT / UPDATE
            User->>Flag: フラグ確認 (Flag=1?)
            Flag-->>User: YES
            User->>Cache: ⑥' 暫定的に追記 (Diff Merge)
        end
        
        Cron->>DB: データ取得完了
    end

    Cron->>Cache: ④ キャッシュA (Base) を書き出し
    Cron->>DB: ⑥ 期間中の差分(Diff)を追加取得
    Cron->>Cache: キャッシュAにDiffをマージ (Finalize)
    
    Cron->>Flag: ⑤ 開始フラグ OFF (Flag=0)
    Note over Time: バッチ終了 (整合性確保完了)
```

■ 整合性担保のロジック

フラグ管理: バッチ開始時にフラグを立て、**現在、キャッシュ生成中である**ことをシステム全体に通知。  
楽観的更新: フラグが立っている間のユーザー投稿は、DBへの保存とは別に、古いキャッシュファイルに対しても暫定的な追記（Diff Merge）を行い、表示上の即時反映を維持。  
事後マージ (追っかけ更新): Cronは「ベースとなるキャッシュ」を作り終えた後、「バッチ開始時刻 〜 現在」の間に更新されたレコードを再度DBから取得（Diff）。最後にこれをベースキャッシュにマージすることで、データ欠損を防止。  















```mermaid
flowchart LR
    %% 開発・デプロイのフロー
    subgraph Local ["1. Local & Source"]
        Dev["Engineer / Tester"]
        GH["GitHub Repository"]
    end

    subgraph CICD ["2. CI/CD Pipeline"]
        CB["Cloud Build"]
        AR["Artifact Registry"]
    end

    subgraph Runtime ["3. Stub Platform (GCP)"]
        direction LR
        CA["Cloud Armor<br/>(IP Whitelist)"]
        CR["Cloud Run<br/>(Python/Flask)"]
        
        subgraph AppLogic ["Stub API Endpoints"]
            direction TB
            E500["/api/snowflake/error (500)"]
            E200["/api/snowflake/success (200)"]
            E403["/api/snowflake/forbidden (403)"]

            %% 垂直に並べるためのダミー接続（不可視）
            E500 ~~~ E200
            E200 ~~~ E403
        end
    end

    %% 正しい用語への修正
    Dev -- "Push" --> GH
    GH -- "Trigger" --> CB
    CB -- "Build & Push" --> AR
    AR -- "Deploy" --> CR

    %% 利用フロー
    Dev -.-> |"HTTPS"| CA
    CA -.-> |"Allow"| CR

    %% 各エンドポイントへのマッピング
    CR --- E500
    CR --- E200
    CR --- E403

    %% スタイル定義
    style Runtime fill:#f9f9f9,stroke:#333
    style CA fill:#fff2cc,stroke:#d6b656
    style AppLogic fill:#ffffff,stroke:#999,stroke-dasharray: 5 5
```




```mermaid
flowchart LR
    subgraph Input ["1. Raw Data"]
        RawDB[("ERP RDB<br/>(ICカードバイナリ)")]
    end

    subgraph Engine ["2. ETL Engine"]
        direction TB
        
        subgraph Logic ["データ加工"]
            direction LR
            Decoder["<b>Binary Decoder</b><br/>サイバネコード解析"]
            API["<b>SaaS Client</b><br/>ジョルダンAPI連携"]
            Calc["<b>Fare Logic</b><br/>運賃計算・マッピング"]
            
            Decoder --> API --> Calc
        end
        
        Base["BaseTasklet (共通基盤)"]
        Base -.-> Logic
    end

    subgraph Storage ["3. Processed Data"]
        MasterDB[("ERP RDB<br/>(精算用レコード)")]
    end

    subgraph Consumer ["4. Business UI"]
        UI["旅費精算システム<br/>(ユーザーが起票)"]
    end

    %% 全体の流れ
    RawDB --> Logic
    Logic --> MasterDB
    MasterDB --> UI

    %% スタイル定義
    style Engine fill:#f5f5f5,stroke:#333
    style Logic fill:#ffffff,stroke:#01579b,stroke-width:2px
    style Decoder fill:#e1f5fe,stroke:#01579b
    style UI fill:#fff2cc,stroke:#d6b656
```





```mermaid
flowchart LR
    subgraph Trigger ["1. Trigger"]
        direction TB
        CSV[("基幹システム出力<br/>(CSVファイル)")]
        Watch["監視ジョブ<br/>(JAR起動)"]
        CSV -- "検知" --> Watch
    end

    subgraph Foundation ["2. ETL連携基盤"]
        direction TB
        Base["<b>BaseTasklet (共通基底クラス)</b><br/>エラー処理・ログ・初期化を自動化"]
        
        subgraph Modules ["再利用可能モジュール群"]
            direction LR
            M1["REST API"]
            M2["FTP"]
            M3["DB Access"]
            M4["File Logic"]
        end
        Base -.-> Modules
    end

    subgraph Evolution ["3. システムの進化"]
        direction TB
        
        subgraph Initial ["初期実装部モジュール"]
            App1["旅費精算連携<br/>(Jordan API)"]
        end

        subgraph PostDeparture ["<b>離脱後にチームが追加</b>"]
            App2["人事連携 (HUE/Company)"]
            App3["ITSM連携 (ServiceNow)"]
            App4["CRM連携 (Salesforce)"]
        end
        
        %% 継承関係
        Initial -- 継承 --- Base
        PostDeparture -- 継承 --- Base
    end

    subgraph Output ["4. 連携先"]
        SaaS["各社SaaS / クラウド"]
    end

    Watch --> Initial
    Watch --> PostDeparture
    Initial --> Modules
    PostDeparture --> Modules
    Modules --> SaaS

    style Foundation fill:#e1f5fe,stroke:#01579b,stroke-width:2px
    style Base fill:#b3e5fc,stroke:#01579b
    style Initial fill:#e1f5fe,stroke:#01579b
    
    style PostDeparture fill:#e8f5e9,stroke:#2e7d32,stroke-width:2px,stroke-dasharray: 5 5
    style App2 fill:#ffffff,stroke:#2e7d32
    style App3 fill:#ffffff,stroke:#2e7d32
    style App4 fill:#ffffff,stroke:#2e7d32
```





```mermaid
flowchart LR
  subgraph Triggers ["1. Hybrid Triggers"]
    direction TB
    T1["<b>定期実行 (Scheduling)</b><br/>JP1 / Task Scheduler"]
    T2["<b>イベント駆動 (File Trigger)</b><br/>基幹システム出力(CSV/TAB)を検知"]
  end

  subgraph Foundation ["ETL連携基盤"]
    direction TB
    Base["<b>BaseTasklet (共通基底クラス)</b><br/>エラー処理・ログ・初期化を自動化"]
     
    subgraph Modules ["再利用可能モジュール群"]
      direction LR
      M1["REST API"]
      M2["Binary Decoder"]
      M3["DB Access"]
      M4["File Processor"]
    end
    Base -.-> Modules
  end

  subgraph Evolution ["3. システムの進化"]
    direction TB
     
    subgraph Initial ["初期実装モジュール"]
      App1["旅費精算連携<br/>(Jordan API)"]
    end

    subgraph PostDeparture ["<b>離脱後にチームが追加</b>"]
      App2["人事連携 (HUE/Company)"]
      App3["ITSM連携 (ServiceNow)"]
      App4["CRM連携 (Salesforce)"]
    end
     
    Initial -- 継承 --- Base
    PostDeparture -- 継承 --- Base
  end

  subgraph Output ["4. 連携先"]
    direction TB
    SaaS["各社SaaS / クラウド"]
    DB[("社内RDB / ERP DB")]
    FILE[("各種ファイル出力<br/>(CSV/TAB等)")]
  end

  %% 全体の流れ
  T1 -- "JAR起動" --> Evolution
  T2 -- "JAR起動" --> Evolution
  Initial --> Modules
  PostDeparture --> Modules
  
  %% アウトプットの多様性を反映
  Modules --> SaaS
  Modules --> DB
  Modules --> FILE

  %% スタイル定義
  style Foundation fill:#e1f5fe,stroke:#01579b,stroke-width:2px
  style Base fill:#b3e5fc,stroke:#01579b
  style Initial fill:#e1f5fe,stroke:#01579b
   
  style PostDeparture fill:#e8f5e9,stroke:#2e7d32,stroke-width:2px,stroke-dasharray: 5 5
  style App2 fill:#ffffff,stroke:#2e7d32
  style App3 fill:#ffffff,stroke:#2e7d32
  style App4 fill:#ffffff,stroke:#2e7d32
   
  style Triggers fill:#fff2cc,stroke:#d6b656
```





```mermaid
flowchart LR
    subgraph UserSide ["1. 社員側 (UX)"]
        direction TB
        Home["<b>Slack App Home</b><br/>投稿ボタン"]
        Dialog["入力ダイアログ<br/>(件名/本文)"]
        UserThread["メッセージタブ<br/>(個人スレッド)"]
        DoneBtn["<b>完了ボタン</b>"]
    end

    subgraph Core ["2. AnonConnect Core (Cloud Run / Slack Bolt)"]
        direction TB
        Logic["<b>スレッド仲介ロジック</b><br/>双方向プロキシ実行"]
        DB[(<b>RDB</b><br/>Thread/Channel ID管理)]
        
        Logic <--> DB
    end

    subgraph AdminSide ["3. 役員側 & 状態管理"]
        direction TB
        ExecChannel["<b>役員専用チャンネル</b><br/>(プライベート)"]
        Canvas["<b>Slack Canvas</b><br/>(案件一覧/ステータス管理)"]
    end

    subgraph Evolution ["4. 自律的進化 (保守チーム拡張)"]
        SNOW["<b>ServiceNow連携</b><br/>(精算・全社公開ワークフロー)"]
    end

    %% メッセージフロー
    Home --> Dialog
    Dialog -- "API" --> Logic
    
    %% 双方向プロキシの表現
    Logic -- "新規投稿" --> ExecChannel
    Logic -- "控え/対話用スレッド" --> UserThread
    
    UserThread <--> Logic
    Logic <--> ExecChannel

    %% 状態管理
    Logic -- "ステータス同期" --> Canvas
    DoneBtn -- "完了検知" --> Logic
    
    %% 進化部分
    Logic -.-> SNOW

    %% スタイル定義
    style Core fill:#e1f5fe,stroke:#01579b,stroke-width:2px
    style Logic fill:#b3e5fc,stroke:#01579b
    style DB fill:#ffffff,stroke:#01579b
    style AdminSide fill:#f5f5f5,stroke:#333
    style Evolution fill:#e8f5e9,stroke:#2e7d32,stroke-width:2px,stroke-dasharray: 5 5
    style SNOW fill:#ffffff,stroke:#2e7d32
```



```mermaid
flowchart LR
    subgraph UserSide ["社員 (送信元)"]
        User["社員A"]
    end

    subgraph Proxy ["AnonConnect (仲介者)"]
        App["<b>Slack App</b><br/>(メッセージの架け橋)"]
        DB[(スレッド紐付けテーブル<br/>ID / タイムスタンプ)]
    end

    subgraph ExecSide ["役員 (返信先)"]
        Exec["役員B"]
    end

    %% メッセージの往復フロー
    User -- "1.アプリに投稿" --> App
    App -- "2.匿名で転送" --> Exec
    
    Exec -- "3.スレッドで返信" --> App
    App -- "4.投稿者に返信" --> User

    %% 匿名性の解説
    note1["<b>【匿名性の本質】</b><br/>お互いの送信元は常に『アプリ』であるため、誰が投稿したか見えない"]
    
    App -.-> note1

    %% スタイル定義
    style Proxy fill:#e1f5fe,stroke:#01579b,stroke-width:2px
    style note1 fill:#fff2cc,stroke:#d6b656,font-size:12px
    style User fill:#ffffff
    style Exec fill:#ffffff
```
