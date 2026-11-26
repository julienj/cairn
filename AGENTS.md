# AGENTS.md

Cairn is an issue tracker for a web agency and its clients: a single
instance holds every client (a workspace each, several projects per
workspace), several issue categories per project, each with its own
workflow, and its internal users and those of the client work on the same
issues, each seeing what their role allows. This file holds what the code
does not say by itself: the decisions taken and the rules that keep them.
How a feature works is read in the code.

## Stack and decisions

Symfony 8.2 on PHP 8.5, installed from `webapp-pack`; `composer.json` has
the exact versions. In place: Doctrine ORM, Messenger (Doctrine transport),
Form, Validator, Serializer, Security, Twig, Mailer, Translation, Stimulus,
Turbo, UX Twig/Live Components, Scheduler, Workflow, Lock, Reprise
(`symfony/reprise`) for the assets, API Platform (`api-platform/symfony`,
without its Doctrine bridge), `symfony/mcp-bundle`, Mercure
(`symfony/mercure-bundle`) for the optional real time and
`nelmio/security-bundle` for the security headers of every answer.

- **Persistence**: Doctrine ORM on PostgreSQL, 17 and 18, and nothing
  else. No soft delete, no bin, no anonymising: what is deleted is
  deleted physically, and three rules say what may be.
  - Current state or configuration (an attachment, a comment, a
    membership, a link, a rule, a release, a tag, a budget line, a
    template) goes freely, by whoever manages it; the journal keeps what
    it said.
  - What holds history is archived, and deleted only while empty: a
    tracker without issues, an account that left no trace
    (`UserRepository::hasHistory()`), a configuration row nothing uses.
  - History goes only on purpose, with its container: an issue under
    `issue.delete`, a project or workspace once archived, a budget once
    closed. `Repository/IssueEraser` is the one place that knows what
    hangs from an issue (`ProjectRemoval` adds what belongs to a project),
    in bulk statements that run no Doctrine listener, so the files go
    afterwards (`RemoveFiles`). Deleting is synchronous and notifies
    nobody.
- **Interface**: server-rendered Twig + Turbo + Live Components, Tailwind,
  no JavaScript framework.
- **Auth**: `form_login` on `/login`; every route requires `ROLE_USER`
  except `/password/…`. Emails are stored lowercase. Nobody types a
  password for someone else: an account is created without one and
  `InviteUser` mails a link. "Forgot password?" answers the same page
  whatever the address. `Security/PasswordTokens` is the only class that
  talks to `symfonycasts/reset-password-bundle`. `bin/console
  app:user:create` creates the first administrator.
- **Single sign-on**: optional, one OpenID Connect provider per instance,
  set by the environment (`OIDC_*`) and signed in by Symfony's
  `oidc_login` on the `main` firewall. `Security/SingleSignOn` is the one
  place that says whether there is one; without it nothing shows and
  `/login/sso` does not exist. How an account signs in is the
  administration's choice (`User::$authMethod`: password or SSO), one or
  the other: an account of the provider holds no password and gets no
  password link, and the provider opens no account that has one. Nobody
  is created at sign-in and no claim grants a role:
  `Security/OidcUserProvider` finds the account by the subject of the
  provider, the first time by the address it vouches for (`email_verified`
  sent and true: a provider that says nothing vouches for nothing), and keeps the
  subject (`UserRepository::bindSubject()`: authenticating is not a
  command). Signing out is local, and nothing follows the provider
  afterwards: whoever left the directory keeps their session and API
  tokens until their account is deactivated here. Several providers
  would be a provider chosen on the account and an authenticator of our
  own: `oidc_login` knows one per firewall, from the configuration.
- **Second factor**: optional, TOTP only, by `scheb/2fa-bundle`, on the
  `main` firewall alone (the `api` firewall has its tokens). An account
  that signs in with a password turns it on at `/security/2fa/enable`, in
  the layout of the sign-in, from its account or when held to it
  (`EnableTwoFactor`: the code of the scanned secret proves it works, with
  no limit on the tries: the secret is on the screen). `CAIRN_TWO_FACTOR`
  (`optional`, `internal`, `all`) says who must, and
  `Security/TwoFactorPolicy` is the one place that answers: whoever must and
  has none is sent to that page by `Security/TwoFactorEnrolment` after the
  password, comes back to the page they asked for, and cannot turn it off.
  Turning it on or off ends the other sessions of the account
  (`User::isEqualTo()` compares the secret) and signs the current one in
  again. The accounts of the identity provider hold none and are never
  asked: the provider has its own, and `User::signInWithSso()` drops the
  secret. There are no backup codes: whoever lost their phone has the
  administration reset it (`ResetTwoFactor`), an administrator who lost
  their own the console (`app:user:reset-two-factor`). A code is good once, at the
  sign-in and anywhere else (`Security/TwoFactorCodes` sends scheb's events,
  so its cache remembers it), and is guessed at five tries per account
  (`Security/TwoFactorThrottling`, `two_factor` limiter), at the sign-in
  and on the page that turns it off. A caller that signs a person in again
  on the session that went through the code sets
  `TwoFactorCondition::SKIP` on the request. Scheb reads the system clock,
  not `ClockInterface`: tests compute codes with OTPHP
  (`TwoFactorHelpers`).
- **API**: REST under `/api` and an MCP server on `/mcp`, for internal
  users, over the same commands and queries as the pages (see [API](#api)).
- **Configuration**: roles, permissions, steps, workflows, fields and link
  types are rows of the database, managed under `/admin/configuration`
  (see [Configuration](#configuration)). No file, no sync.
- **Workflow engine**: the Symfony Workflow component, state machines built
  at runtime from the configuration (`framework.workflows` stays empty).
  They enforce structure for the roles held to the workflow; who may
  trigger a transition, and who moves issues freely instead, is decided by
  `AccessControl`, never by a workflow guard.

Install missing capabilities with `composer require <package>` and let the
Flex recipe register the bundle and generate its config. Don't hand-edit
`config/bundles.php` or hand-write a bundle's base config.

## Vocabulary

Use the English term in code, the translated one in the UI, through
translation keys. Never mix them.

| Code             | UI (en)           | UI (fr)          | Meaning                                                          |
|------------------|-------------------|------------------|------------------------------------------------------------------|
| `Issue`          | Issue             | Demande          | The tracked object. Key `WORKSPACE-PROJECT-123`; a workspace or project key is 2 to 5 uppercase letters or digits, one letter at least (`Workspace::KEY_FORMAT`, the one place that says so in PHP). |
| `Tracker`        | Category          | Catégorie        | Type of an issue within a project, bound to one workflow.        |
| `Workflow`       | Workflow          | Workflow         | Places + transitions + the fields asked.                         |
| `Place`          | Step              | Étape            | Where an issue stands. Declared once for the instance, used by the workflows that name it. |
| `Transition`     | Action            | Action           | Named button that moves an issue to another place.               |
| `aside` / `path` | Off the path / Path | Hors parcours / Parcours | The path is the places of a workflow in their order, as its diagram links them; a place set aside stands off it, reached from a place of the path (`WorkflowDefinition::$aside`). Drawn, never enforced. |
| `PlaceStatus`    | (internal)        | (interne)        | Family of a place: Open, InProgress, Waiting, Closed. Never shown to users, who see places; only the configuration pages show it. |
| `Criticality`    | Criticality       | Criticité        | Low, Normal, High, Blocking. First-class column, not a field.    |
| `Field`          | Field             | Champ            | Global attribute, asked by workflows.                            |
| `Link`           | Link              | Lien             | Related, Duplicates/DuplicatedBy, Blocks/BlockedBy.              |
| `Workspace`      | Workspace         | Espace           | Group of projects sharing memberships. A client is a workspace: no organisation entity. |
| `Project`        | Project           | Projet           |                                                                  |
| `Role`           | Role              | Rôle             | Managed in the administration, with its permissions and how it moves issues: freely, or by the actions of the workflows. |
| `Membership`     | Access            | Accès            | Role of a user on a project or a workspace.                      |
| `Team`           | Team              | Équipe           | People who answer as one, internal or external for good; holds a role on a project or a workspace as a user does (`TeamAccess`). |
| `internal` flag  | Internal/External | Interne/Externe  | Internal user (who runs the instance) vs external user (a client). |
| `company`        | Company           | Société          | Free text on a user.                                             |
| `private`        | Private           | Privé            | Hidden from external users.                                      |
| `AuthMethod`     | Sign-in method    | Méthode de connexion | Password, or the identity provider of the instance (SSO).    |
| `Release`        | Release           | Version          | Planned delivery, optional per project.                          |
| `Tag`            | Tag               | Étiquette        | What the issues of a project are sorted by, several per issue, optional per project. |
| `JournalEntry`   | History           | Historique       | Immutable journal, keeps the labels of the time.                 |
| `Attachment`     | File              | Fichier          |                                                                  |
| `ConfigChange`   | History (configuration) | Historique (configuration) | A change made to the configuration.            |
| `Import`         | Import            | Import           | An export archive of another tracker brought into a project, once. |
| `AutomationRule` | Rule (Automations) | Règle (Automatisations) | A trigger, conditions, actions, on the issues of a project. |
| `AutomationRun`  | Execution         | Exécution        | What a rule did on an issue, once.                               |
| `referent`       | Referent          | Référent         | The member a role answers through on a project.                  |
| `ProjectTemplate` | Template         | Modèle           | Trackers, rules and roles of teams copied into a new project.    |
| `Budget`         | Budget            | Budget           | An amount of time a client committed to on a project.            |
| `BudgetLine`     | Line              | Ligne            | What a budget is debited of, written by hand.                    |

## Architecture

Standard Symfony layout plus a command/query separation carried by two
Messenger buses. No modules, no hexagonal layers, no event sourcing.

```
src/
  Entity/          flat; persisted enums in Entity/Enum/
  Repository/      one per entity, never the viewer; plus the DQL reads share
  Message/         Command/<Domain>/, Query/<Domain>/, CommandBus, QueryBus
  MessageHandler/  exact mirror of Message/, one handler per message
  Event/           domain events, one per command that changes an issue
  Controller/      one folder per zone (Project/, Admin/, Security/)
  Form/            one folder per domain: types and their data objects; Type/
  Security/        AccessControl and its SQL twin Visibility, voters, tokens
  Twig/Components/ PHP-backed components
  Config/          the configuration as the application reads it
  Workflow/        state machines built from the configuration
  Notification/    subscriptions, the mail queue, the mails
  Realtime/        the Mercure signal
  Api/             the REST API and the MCP tools, self-contained
  Forge/  Import/  Automation/  Budget/  Markdown/  Attachment/
  Command/         console commands
```

- **Entities carry intent** (`moveTo()`, `makePrivate()`, `close()`), never
  generic setters, never services, and hold no collection.
- **No catch-all folders**: no `Service/`, no central `Enum/`, no `Dto/`. An
  enum or DTO lives next to what gives it meaning; when an enum has two
  owners, persistence wins.
- **Controllers and templates are grouped by zone**, each with its URL
  prefix, its shell and its base template `templates/layout/<zone>.html.twig`
  (blocks `page_title`, `content`). Components are grouped by what they
  display (`Layout/`, `Issue/`, `IssueList/`, `Board/`), not by zone.

  | Zone        | URL                           | Controllers            | Templates              |
  |-------------|-------------------------------|------------------------|------------------------|
  | application | `/`, `/issues/…`, `/projects` | `Controller/` root     | `templates/` root      |
  | account     | `/account/…`                  | `Controller/` root     | `templates/account/`   |
  | project     | `/MD/SPT/…`                   | `Controller/Project/`  | `templates/project/`   |
  | admin       | `/admin/…`                    | `Controller/Admin/`    | `templates/admin/`     |
  | security    | `/login`, `/security/…`       | `Controller/Security/` | `templates/security/`  |
  | api         | `/api/…`, `/mcp`              | none: `Api/`           | none                   |

- **URLs carry keys, not ids, and one route per need.** A project action
  receives `#[CurrentProject] Project $project` and its class carries
  `#[IsGranted('view', subject: 'project')]`. Read-only projects
  (`Project::isReadOnly()`) are refused by `AccessControl` for every
  permission, so no controller checks it. A list with another scope is
  another route, never a query parameter that switches the scope.
- **A new subject is a route, a page and a navigation item**, never a tab
  nor a card added to another page (`project/settings/base.html.twig`,
  `layout/account.html.twig`).

### Writes and reads

- **Writes are commands.** Every mutation is a readonly message with
  `#[Assert]` constraints in `Message/Command/<Domain>/`, handled on
  `command.bus`, whose middlewares validate and wrap the handler in a
  transaction: the flush is theirs, handlers only persist. Controllers,
  Live components, webhooks and console commands build a command and call
  `CommandBus::dispatch()`; they contain no business rules. Authorization
  failures are `AccessDeniedException`, business refusals a domain
  exception next to the handler, a `TranslatableInterface` when a page
  shows it as it is.
- **One message to create, one to change.** `UpdateX` is PATCH style: a
  null property keeps its value (`UpdateIssue::NOBODY` unassigns), and
  states such as archived are properties, not actions. The handler checks
  every change before applying any. Never split an `UpdateX` per property
  or per form. A message of its own is for a business action
  (`ApplyTransition`, `MoveIssue`, `ChangeTracker`) or a sub-resource.
- **Forms feed commands.** A form binds to a mutable data object in
  `Form/<Domain>/`; the controller turns a valid submission into the
  command. An invalid form is rendered with status 422.
  Configuration-driven fields use `translation_domain: false`. A form whose
  shape depends on a choice of the same page is hosted by a Live component.
- **A read has three homes.** A repository answers "give me this entity"
  and never takes the viewer nor a flag that stands for them. A read that
  depends on who is looking, on filters typed by the user, or that merges
  entities is a `Query<TResult>` in `Message/Query/<Domain>/`, asked through
  `QueryBus::ask()`; its handler holds all of its SQL and is the only place
  that applies `Security/Visibility`. Nothing is split out of a handler
  until a second one needs the same piece; those shared pieces
  (`IssueOrder`, `SearchText`, `Pagination`, `EntityList`) live in
  `Repository/`. Queries never mutate; commands return nothing beyond the
  created identifier.
- **A query returns entities, not read models.** A result object exists
  only for what no entity carries, and it holds the entities instead of
  copying their fields (`IssueDetail`, `ProjectSummary`): a second model
  would break the pairing of `AccessControl` with its SQL twin.
- **The handler records a change; side effects listen to it.** A command
  that changes an issue ends with one call to
  `IssueHandlerSupport::record()`, given the domain event (the issue, the
  actor, the journal entries): one event per command and per issue,
  dispatched inside the transaction. What must happen after the commit
  waits for `postFlush` and dispatches with `DispatchAfterCurrentBusStamp`.
  Three commands do not call `record()`: `RankIssue` (arranging a board is
  not an activity), `DeleteIssue` (dispatches `IssueDeleted` itself) and
  `ImportIssue` (would date the issue of today and notify). An event has an
  actor, or the name of the rule that acted (`IssueEvent::$rule`), or
  neither for a forge.

### Authorization and visibility

- **One authorization service.** `Security/AccessControl` takes the acting
  user as an explicit argument and resolves the effective role (a
  membership or the access of a team, on the project or its workspace, the
  broadest wins). Handlers call it directly;
  voters are thin adapters for `#[IsGranted]` and Twig. The signed-in user
  is read from `Security/Viewer`.
- **The account says what one sees, the role what one does.** Being
  internal opens every project to reading, what is private included, and
  the API; it grants no role. Every action is asked of `isGranted()` or
  `canTransition()`: a test on `isInternal()` in a handler is visibility or
  a mistake. An administrator holds every permission without being a
  member.
- **Visibility is written twice and tested as one**: change both sides.
  - Issues, projects, trackers: `AccessControl::canSee()` / `canView()` and
    `Visibility::restrictIssues()` / `restrictProjects()` /
    `restrictTrackers()`, compared by `SearchIssuesVisibilityTest` and
    `ListVisibleProjectsTest`. Every handler that lists them goes through
    `Visibility`.
  - Private comments, their files and links: `canSeeComment()`,
    `canSeeAttachment()` and the SQL of `ShowIssueHandler`, compared by
    `ShowIssueVisibilityTest`.
- **Who edits what: the author, or the right on others**
  (`AccessControl::canEditIssue()`, `canEditComment()`). Deleting an issue
  is a right (`issue.delete`), never the author's.
- **A role moves issues freely or by the actions, never both**
  (`RoleDefinition::$freeWorkflow`, asked of `AccessControl::canMoveFreely()`,
  the one place that says so). Freely: any place of the workflow and
  another tracker, no action offered nor taken, an administrator included.
  Otherwise: the actions that name the role and nothing else, each with
  the comment it requires. It is a property of the role, not a permission,
  and independent of internal-only. An action names roles held to the
  workflow only: a role that is freed leaves those that named it, for
  good (`UpdateRoleHandler`), which its dialog says before it is done. An
  action may name no role: nobody takes it, and the diagram leaves it out.
- **A team holds a role as a person does, and nothing else.**
  `AccessControl::roleOn()` reads the accesses of the teams someone is
  part of with their memberships (`RoleGrant`): the broadest wins,
  whoever it was granted to, and a team never narrows what a member
  holds. The SQL side is in `Visibility::restrictProjects()` and
  `ListProjectMembersHandler`. A team is internal or external for good:
  an internal team takes internal users only, and an internal-only role
  goes to an internal team only, so the rule is read on the team and
  nobody walks its members. On a project its role must widen the one the
  team holds on the workspace; it is compared with itself only, never
  with what its members hold. Teams are the administration's, and a
  project manager grants one a role on their project. The teams of a
  project are asked of `ListProjectTeams`, as its people of
  `ListProjectMembers`; `TeamAccessRepository::holdsRoleOn()` asks it of
  one team. `Repository/RoleUsage` is the one place that counts where a
  role is in use: people, teams, templates. A referent stays a
  person: `Membership` alone carries the mark.
- **Some permissions are internal-only** (`Permission::internalOnly()`):
  those that show what external users never see, the configuration of a
  project among them, as a whole. What is only risky goes to any role an
  administrator chooses.
- **The configuration of a project is a permission per subject**
  (`project.configure` for its general settings, `tracker.manage`,
  `tag.manage`, `automation.manage`, `repository.manage`, and
  `project.manage_members` for its access): each opens its page, its item
  of the navigation and its commands, none supposes another, and nobody
  reads a page without managing it. A new subject comes with a permission
  of its own.
- **A live component that shows private data checks who draws it, each
  time** (`guard()`): its endpoint is not under the route of its page, so
  neither `/admin` nor the page's `#[IsGranted]` protects it. The components
  of the page of an issue share `Issue/GuardsIssue`.

### Issues, lists and kanban

- **The workflow is the contract with the client**: its actions are for
  the roles held to it, and say nothing of who moves freely.
  `AccessControl::movesFor()` lists where a user may take an issue (every
  place, or where their actions lead); `MoveIssue` is the one place that
  applies it, an action going through `IssueHandlerSupport::transition()`,
  the one path a transition takes. A caller dispatches `ApplyTransition`
  only when it names the action or sends a comment. The step is a menu
  for who moves freely only; the kanban serves both. `IssueHandlerSupport::arrive()` is called wherever a
  place is taken. On the page of an issue the actions of others are never
  offered, and there is no "whose turn" indicator; the diagram of its
  workflow, a dialog of that page (`/workflow`), is for whoever sees the
  issue and names every action with its roles, the step of the issue
  outlined.
- **The diagram of a workflow is drawn for whoever it is explained to**
  (`Workflow/MermaidDiagram`): the path its issues follow, its places in
  their order without those the workflow sets aside
  (`WorkflowDefinition::path()`), each of these hanging from the place of
  the path it stands beside, closed or not, and over it the actions, each
  with the roles that trigger it, read from the configuration: no role is
  named in code. `aside` is read by the diagram and by the
  step menu of an issue, which draws the same path, and holds nobody.
- **An issue is given to a person or to a team, never both**
  (`Issue::assignTo()`, `assignToTeam()`): giving it to one takes it from
  the other, and `UpdateIssue::NOBODY` from both. A team is assignable
  where it holds a role (`IssueHandlerSupport::assigneeTeam()`), as a
  person is. "Unassigned" means neither, everywhere it is asked: the filter,
  the group, the condition of a rule. The journal keeps the name, of the
  person or of the team. A team that holds issues is not deleted.
- **`journal_label`** (`Twig/JournalLabelExtension`) is the only place that
  reads what each journal type recorded, for the thread and the mails.
- **Releases are optional, without a setting**: a project without any
  release shows none of it.
- **Tags are optional the same way, and belong to one project**: a closed
  list its managers keep (`tag.manage`), in a colour of a closed
  palette (`TagColour`, a token each). An issue carries several
  (`IssueTag`), put on and taken off one at a time (`TagIssue`,
  `UntagIssue`, under `issue.set_tags`), each journaled; deleting a tag
  takes it off its issues without a word. Every reader of the project
  sees them. The lists of a project filter by them, an issue showing
  when it carries one of those chosen; no list groups by them, no rule
  and no template names them, and the API only reads them.
- **An issue list is an address**: the route fixes a `ListScope`, the query
  string only arranges (`Form/Issue/IssueListFilters`; an unknown value is
  ignored, never an error). `IssueList/ListChoices` is the one place that
  builds the `SearchIssues`, for the table and the kanban columns alike.
  A GET never writes: `ListView` is remembered by a PUT.
- **Users filter and group by place, never by status.** The key of a group
  is written in SQL (`IssueOrder::groupKey()`) and in PHP
  (`IssueGrouping::keyOf()`), compared by `SearchIssuesGroupingTest`.
- **The kanban is a view of one tracker's list**, loaded column by column,
  never whole; `Board:Move` is the only component that acts.
  `Issue::$boardRank` orders a column and nothing else: no table offers
  it, the API refuses it.
- **There is no bulk command.** The selection page dispatches the ordinary
  commands for each issue, each in its own transaction; a refusal stops its
  issue and leaves the others done. No transition is named in bulk.

### Budgets

- A project has budgets once it has a unit (`Project::$budgetUnit`);
  without one every budget command is refused.
- **The budgets know nothing of the workflow, and the issue nothing of
  them.** A line is written by hand; its handlers never call `record()`: no
  journal entry, no event, no mail. Only an automation rule
  (`ActionType::MarkBudgetLines`) ties a line to an action. Do not bring an
  estimate back into the issue, its workflow or its history.
- `BudgetLineRepository::totalsByBudget()` is the one place that says what
  lines count for: only an approved line is consumed.
- **Quantities are integers of minutes**; the unit is only what is shown.
  `Budget/Quantities` is the one place that converts and formats; a form
  converts with `fromInput()` so a field left as shown rewrites nothing.
- `AccessControl::canReadBudgets()` says who reads (it outlives archiving),
  `canSeeQuantity()` who reads the quantity of a line to estimate. Every
  reader gets every line, so the balance is the same for all.

### Notifications and real time

- **Notifications are subscriptions, and the events are the policy.** The
  `#[AsEventListener]`s of `NotificationDispatcher::collect()` are the
  whole list of what notifies; `Notification/SubscriptionRules` never
  subscribes someone who may not follow the issue.
- **A team follows an issue as one** (`IssueTeamSubscription`), from the
  moment it is given the issue and afterwards, as a person does. Nobody
  is subscribed for it: `NotifyIssueChangeHandler` reads who is part of
  the team when the change leaves, so whoever joins is told and whoever
  leaves is not. Any member unsubscribes the team, for all of it
  (`UnfollowIssueAsTeam`); nobody opts out alone of what their team
  follows. An external team follows nothing private, as an external
  person, and a team follows only where it holds a role.
- **Following takes a role on the project**
  (`AccessControl::canFollow()`): an internal user reads every project
  and an administrator acts on each, but neither is written to about a
  project they hold no role on, as neither can be given its issues. It
  is asked when someone is subscribed and again when a mail is built,
  as what they may read is: whoever loses their role stops receiving,
  with nothing to delete, and receives again with a role. A team follows
  on the same terms, asked each time its subscription is read
  (`IssueTeamSubscriptionRepository`). Whoever is written to through a
  team reads which on the page of the issue (`Issue:Follow`).
- **A mail is queued, then grouped.** `NotifyIssueChange` (ids only) writes
  a `PendingNotification` per recipient; `ProjectMailSender` (Scheduler)
  sends a group once things calmed down. `ChangeFilter` says what one
  person reads of a change, and is asked again when sending. Each group is
  one transaction that starts by deleting its rows, so two workers never
  send twice, without a lock; the mail must land in the Doctrine transport
  in that transaction.
- **A worker must consume `async` and `scheduler_default`**: without the
  second no issue mail leaves and no time rule runs. The `failed` transport
  may hold a password link in clear: purge it, never keep it.
- **Real time is a signal, and it is optional**
  (`Realtime/ProjectTopics::enabled()`). An update carries ids only; the
  page reads again through its own queries. Never broadcast HTML or data:
  everyone would get the same. A project has two private topics (internal,
  and what an external user may see); the token goes in an `Authorization`
  header, never a cookie, and the hub must refuse anonymous subscribers.

### Automations

- **A rule is a trigger, conditions and actions, from closed lists**: no
  branch, no script, no variable. A new type of step is an enum case
  (`TriggerType`, `ConditionType`, `ActionType`) with its `Parameter`s, its
  labels and its effect in the one class that reads the enum
  (`RuleEngine::triggers()`, `RuleConditions`, `RuleExecutor`), never a
  form: the `ParameterKind` alone decides the check and the field.
- **A rule names roles, not people** (`Target`, resolved by
  `Automation/Targets` through the referents), so a template copies it as
  it is. It may name a team as well (`team:<id>`), which is the same on
  every project and needs no referent: a team that holds no role on the
  project is skipped like a role without a referent, and the page of the
  rules says so first (`TeamsWithoutAccess`). Where a rule is written is a `RuleContext` (a project or a
  template); the checker, the form and `RuleSentence` know nothing else.
- **A rule acts without an author, through the usual doors**: intent
  methods, journal entries and comments signed with its name, then
  `record()`. It asks no right and follows no workflow. Rules do not go
  through the issue commands, which are what a person may do: do not add
  an `actorId: null` to those. A rule must not fail the command that
  triggered it: what cannot be done is skipped and reported in its
  `AutomationRun`.
- Rules follow one another three deep (`RuleEngine::MAX_CHAIN`), once per
  issue in a chain. Time is counted from the last person or forge
  (`Issue::$lastActivityAt`), never from a rule; "due" is written once, in
  `AutomationRuleRepository::findDueIssues()`.
- A rule keeps what it says as data: `Automation/RuleReferences` finds the
  rules that name a step, an action, a role or a team before a change
  leaves them without their object.

### Forges and imports

- A delivery proves where it comes from in one of two ways: the secret of
  the link, which every linked repository has of its own, or the secret of
  a system hook of the forge's instance (`FORGE_GITLAB_HOOK_SECRET`,
  optional, `ForgeApi::hasInstanceHook()`), which stands for every link of
  the repository named and installs nothing. Either way a repository
  nobody linked leaves nothing, and none links itself: what the instance
  hook delivers besides is accepted and ignored. The README says what the
  instance hook costs; the two change together.
  `Forge/GitLab/GitLabRequestParser` authenticates and tames everything it
  reads, then emits neutral events (`Forge/Event/`). Code references are
  private, without author, notify nobody and never apply a transition.
  Another forge is another folder behind the same `ForgeApi` and events.
- An import brings an archive in Cairn's own format, once and silently.
  The format (`Import/ImportManifest`), the example of
  `templates/admin/import/example/` and `ImportArchiveTest` change
  together. What lands in the project goes through commands; every item
  leaves an `ImportRecord` and is never written twice, so a run resumes.

### API

- **A second door, not a second application.** API Platform with
  `doctrine: false`: a resource is a readonly class of `Api/Resource/`,
  never an entity; a provider asks the `QueryBus`, a processor dispatches
  the command through `Api/State/CommandDispatcher` then reads the result
  again as the viewer. Neither holds a rule. Nested values are classes of
  `Api/Resource/Value/`, never arrays.
- URLs carry the keys of the application. What the viewer may not see does
  not exist: 404 from `Api/State/Lookup`, before any command. A filter the
  API does not understand is a 400 or a 422, never ignored as in the pages.
- One operation per command; PATCH is a JSON merge patch, so a property
  left out neither changes nor asks for its right. Add an operation when a
  program needs it, not before.
- **A token signs a request, the account opens the API.** The `api`
  firewall is stateless and knows personal tokens only (`ApiToken`: hashed
  secret, revoked, never deleted). `AccessControl::canUseApi()` is checked
  at every request; nothing else in `Api/` assumes an internal viewer. The
  scope is asked by `access_control` from the method.
- **The MCP server is a few tools over the same code**: a class of
  `Api/Mcp/` with `#[McpTool]`, handed to the processors of `Api/State/`.
  Each tool declares its scope itself and sets `validate: true`. A comment
  written through a tool is private unless asked otherwise. Assistants fill
  unused arguments with nulls and empty strings, so neither means anything
  (`UpdateIssueTool::NONE` unassigns).

### Account and administration

- Who someone is stays the administration's: an account changes its own
  `mailGrouping`, the kinds of activity it hides from the thread of every
  issue (`hiddenActivity`), password and picture only. `<twig:User:Avatar>` is the
  one place that draws a person.
- Everything under `/admin` requires `ROLE_ADMIN`. Its commands carry no
  actor; those a project manager reuses take an optional `actorId`, checked
  by `MessageHandler/Command/ProjectActor`: that project only, never a
  workspace membership, never archiving. Keys never change once created.
- A project template is a copy and nothing more (`TemplateCopy`): the
  project keeps no trace of it, and a template is never applied to a
  project that exists. It copies trackers, rules and the roles it gives
  to teams (`TemplateTeamAccess`), so that a rule aiming at a team finds
  it on the project; a team that holds as much on the workspace already
  is given nothing more.

## Frontend

- **Assets**: Vite through Symfony Reprise, not AssetMapper; JavaScript
  dependencies are npm packages. Tailwind v4 is configured in CSS
  (`assets/styles/app.css`), no `tailwind.config.js`. Tests render no asset
  tags, so they need no build.
- **Colours are roles, light and dark.** Every colour is a token of
  `@theme` with a `light-dark()` value. Templates write `bg-surface
  text-muted`, never a step of the palette (`bg-white`, `text-gray-500`):
  `PaletteClassesTest` refuses them. A new role comes with both values and
  its pairs in `ColourRolesTest`. The theme is the system's unless chosen,
  kept in the `cairn_theme` cookie, not on the account.
- **The UI kit is anonymous Twig components** in
  `templates/components/Ui/`, used with the `Ui:` prefix. Components with a
  PHP class live in `templates/components/<Group>/` without the prefix: it
  alone tells a building block from a component that reads data or acts.
  - Classes always go through a CVA recipe (`html_cva`), and the root
    renders `class="{{ recipe.apply({…}, attributes.render('class'))|tailwind_merge }}" {{ attributes }}`.
  - Props are what changes the look; `name` and `value` are plain
    attributes forwarded to the inner control. The exception is the live
    binding: a form control takes a `model` prop.
  - Variables a component sets carry its name (`buttonClasses`,
    `avatarInitials`): its content blocks see them, so a bare `item` would
    shadow the caller's.
  - A component earns its place with a choice of classes or a structure.
    It takes scalars as props and content as blocks, not a list to loop
    over.
- **Every component has its Storia view** in
  `storia/components/<group>/<name>.yaml`, with 2 to 5 variants named after
  one state each. A variant that composes components is a Twig template
  next to the YAML that never branches on the variant. Storia holds
  components only, never pages.
- **Symfony forms render through `templates/form/theme.html.twig`**, each
  block delegating to a `Ui:Form:*` component; types the framework lacks
  live in `src/Form/Type/`. Form controls style their disabled state.
- **Icons**: `<twig:ux:icon name="lucide:x" />`, imported locally with
  `ux:icons:import`.
- **Components of one page keep each other up to date through a domain
  event** (`issue:changed`): the one that acted emits it, the others
  redraw in a `#[LiveListener]`; no redirect from a live action when other
  components hold state. They share `Twig/Components/DispatchesCommands`
  (`tryCommand()`, `announce()`); a toast is a `toast:show` event for the
  one `Ui:Toaster`. The live components of a page are siblings, never
  nested: a parent that redraws replaces a focused child with its
  placeholder.
- **Text users write is markdown**, edited in `Ui:Form:Editor`
  (`Form/Type/MarkdownType`) and displayed with the `markdown` filter,
  never raw. `#MD-SPT-12` links an issue only for a reader who may see it,
  and a mention names a member of the project only, or a team that holds
  a role on it (`@[Name](team:3)`), which then follows the issue. An
  address shown as a link is one of the web (`http://`, `https://`),
  checked where it enters.
- **Files are attachments**: pending while what they go with is being
  written, then claimed by the command that saves the text
  (`IssueHandlerSupport::claimAttachments()`). A file follows what it goes
  with, privacy included. A file input in a live component goes through the
  `file-picker` controller.
- **Discreet actions are icon buttons shown on hover** (`hover-actions`).
  A deletion confirms in place, never through `confirm()`.
- **Creating: a dialog to name, a page to compose.** A dialog when the form
  is short, of a fixed shape and filled without reading anything else. A
  dialog of a controller's page has its own address (`/new`, `/{id}`) and
  loads through the `modal` Turbo frame
  (`Controller/OpensDialog::renderWithDialog()`); the form names its
  address as its `action`, and every page a dialog redirects to must answer
  the frame its empty self.
- **Choosing among many is a `Ui:Picker`, typing with suggestions a
  `Ui:Form:Combobox`.** What has no bound is searched on the server, never
  cut to a first page.
- **JavaScript is Stimulus only**, one small controller per behaviour in
  `assets/controllers/`, a heavy one marked `/* stimulusFetch: 'lazy' */`.
  Write one when a component needs it, not before. No inline scripts.

## Configuration

Roles, permissions, steps, workflows, fields and link types are rows of
`config_…` tables. Criticality levels, statuses, permissions, field types
and the looks of an action are fixed enums in code.

- **Everything has a key, fixed once created**, made from its label
  (`ConfigWriting::key()`). It is what the data, the journal, the API and
  the rules name; labels change freely. The data points to these rows with
  a relation, so the database refuses an unknown key and the deletion of a
  row in use (`ConfigurationReferencesTest`); entities take the row and
  give back its key only.
- **The application reads an immutable definition, never the rows.**
  `Config/DefinitionLoader` is the one place that knows how the tables fit;
  everything else asks `ConfigRegistry` for the `InstanceDefinition`, read
  once per request or message, without a shared cache.
  `ConfigRegistry::row()` is the one way to a row. The definition judges
  nothing, so an unsound row is read and the administration still opens;
  an unconfigured instance has an empty definition and no page fails.
- **A change is a command, in force as soon as it ends**: no draft, no
  publication, so every command must leave a configuration the application
  can run. A rule of the configuration is written twice, in the handler
  that refuses a change (`ConfigChangeException`) and in `ConfigValidator`,
  which judges a whole definition: change both together.
  `ConfigDeletions` is the one place that says whether something may be
  deleted. A handler checks before it writes and ends with
  `ConfigWriting::settle()`. The commands implement `ConfigurationCommand`
  and run one at a time (`ConfigurationLock`).
- **An issue is open or closed by its step**: no issue copies the status of
  its place. SQL joins the place and reads `step.status`
  (`IssueOrder::step()`); PHP and Twig read the definition
  (`WorkflowProvider::placeOf()`, `issue_status(issue)`), never the
  relation. `Issue::$closedAt` is a fact of its own, set by a move only.
  `RemoveWorkflowPlace` is the one configuration change that writes issues.
- **A choice of a field has a value kept for good**; only its label
  changes.
- **A new instance installs its starter configuration, once**
  (`Config/StarterKit`, `app:config:install`): nothing is ever compared nor
  applied again. A later version changes the configuration with Doctrine
  migrations written in SQL against the `config_…` tables. A new
  `Permission` case is granted to nobody until a migration or an
  administrator grants it.
- **Addresses**: a list in the plural (`/places`, `/places/new`), one
  element in the singular followed by its key (`/place/to_test`), so any
  key is allowed, `new` included.

## Conventions

- Code, identifiers, comments, tests, fixtures, commit messages and this
  file are in English. French lives only in the translation catalogues.
- **"Agency" is not a term of the application**: say *internal* and
  *external* users. *Client* stays where it names a role of the
  configuration, a workspace, or fixture data.
- **One language per instance** (`CAIRN_LOCALE`), no per-user locale.
  Interface strings go through translation keys named by domain
  (`status.open`, `issue.action.create`), never English sentences; no
  hardcoded labels in Twig or PHP; English and French are shipped, and
  every `Permission` case comes with `permission.<value>.label` and `.help`
  (`PermissionTest`). Content written by administrators or users
  (configuration labels, issue and comment text) is data: displayed as-is,
  never passed to the translator.
- Follow https://symfony.com/doc/current/best_practices.html: PHP
  attributes for all framework metadata (no YAML or XML routing),
  autowiring with `#[Autowire]` and `#[Target]` where a type-hint can't
  express it, thin controllers extending `AbstractController`, `readonly`
  for DTOs, commands, events and definition objects (not for a service
  that may become lazy), and the framework before any infrastructure of
  our own.
- Never call `new \DateTime*()`, `date()` or `time()`: entities use
  `Symfony\Component\Clock\now()`, services inject `ClockInterface`, tests
  freeze time with `ClockSensitiveTrait::mockTime()`.
- **A schema change is a migration**, made with `castor db:migration`
  (`doctrine:migrations:diff`) once the entities say it, and never edited
  once merged: an instance ran it. The tables the framework keeps in the
  database (sessions, `cache.app`, Messenger) are in the migrations too,
  which are therefore generated in `dev`, never in `test`. The tests
  derive their schema from the entities (`tests/bootstrap.php`); `castor
  qa:migrations` and the CI check that the migrations give the same.
- `.env` holds defaults only. Real secrets go in `.env.local` or the
  secrets vault.

## Development environment

Development runs in Docker (`compose.yaml`), driven by Castor (`castor.php`
and `.castor/`). Commands run inside the `cli` container, never on the
host.

- `castor install` builds everything and loads the development data;
  `castor start` and `castor stop` afterwards; `castor list` shows every
  task. `castor start` applies the migrations a pull brought (`castor
  db:migrate`); no container migrates by itself.
- Services: `php` (FrankenPHP with its Mercure hub,
  <http://localhost:8000>), `cli`, `messenger` (`messenger:consume async
  scheduler_default`), `vite`, `storia`, `database` (PostgreSQL 18) and
  `mailer` (Mailpit, <http://localhost:8025>) and `authelia` (single
  sign-on, below).
- `castor console <command>`, `castor composer …`, `castor npm …`,
  `castor sh`. The `messenger` worker loads the code once: `castor
  workers:restart` after changing what it runs.
- `php` runs FrankenPHP in worker mode: the kernel and its services
  outlive the request, as they outlive the message in `messenger`. A
  service that remembers something implements `ResetInterface`, or
  forgets it where its work starts. The workers start again by themselves
  when the code changes (`FRANKENPHP_WORKER_CONFIG` in `compose.yaml`); a
  path they should follow is added there.
- `castor db:fixtures` resets the dev database and loads
  `src/Story/AppStory` then `VolumeStory`, through the real commands under
  a mocked clock, nothing drawn at random. Every password is `cairn`;
  `admin@cairn.test` is an administrator. Léa Fontaine signs in through
  Authelia instead.
- Single sign-on: the `authelia` service is the identity provider of the
  stack (`OIDC_*` in `.env.dev`, `docker/authelia/`), at
  <https://authelia.localhost:9443>. Both it and `oidc_login` require
  HTTPS, so the Caddy of `php` serves it with a certificate of its own
  authority (`CADDY_EXTRA_CONFIG` in `compose.yaml`: the browser warns
  once), which Caddy installs in the container so that PHP trusts it:
  nothing in `config/` knows Authelia. A `*.localhost` name is the container for PHP and the host
  for the browser, hence the same port on both sides. Two people, password
  `cairn`: `lea` is Léa Fontaine of the development data, the one account
  that signs in that way and so has no password; `hugo` has no account
  and is refused. Another provider goes in `.env.local`, with the
  redirect URI `http://localhost:8000/login/sso/callback`.
- By hand: `castor console app:notifications:send --now` sends the mails
  that wait, `app:automations:run` the time rules, `app:attachments:prune
  --dry-run` the cleaning of files.
- When something fails, read `var/log/dev.log` and the web profiler before
  changing code. Discover, don't guess: `debug:router`, `debug:container`,
  `debug:autowiring <name>`, `debug:config <bundle>`, the source under
  `vendor/`.
- Prefer `castor console make:*` with every argument and
  `--no-interaction`; a maker that prompts hangs a non-interactive shell.

## Production image

- **One `Dockerfile`, two targets**: `dev` is what `compose.yaml` runs,
  `prod` what is published (`.github/workflows/docker.yaml`), with
  `compose.prod.yaml` as its example. The `prod` image is the web server
  and the worker alike: the command chooses. It serves in worker mode, as
  `dev` does.
- **A container keeps nothing.** Sessions, locks and the `cache.app` pool
  (the state of the schedule, the rate limiters, the restart signal of the
  workers, the MCP sessions) are in the database, in every environment but
  `test`; logs go to stderr; files live under `/data` (`ATTACHMENTS_DIR`,
  `IMPORTS_DIR`), the one volume. Nothing that must last, or that two
  processes share, is written under `var/`.
- **The entrypoint writes nothing unless asked to.** It refuses to start
  without its secrets, and with `CAIRN_AUTO_MIGRATE=1` the web server
  applies the migrations before it starts: the web server alone, so two
  containers never migrate at once, and the worker of `compose.prod.yaml`
  waits for it. Nothing else is installed at startup.
- The image is configured by its environment alone, runs unprivileged and
  on a read-only root filesystem (`/data` and `/tmp` apart).
  `docker/Caddyfile.prod` has one site address: an address per scheme
  would be a Mercure hub per scheme. The hub checks who a token was signed
  for, so `MERCURE_PUBLIC_URL` is a full address, which the entrypoint
  names the hub by.
- `docker/smoke-test.sh` starts the image the way `compose.prod.yaml`
  tells an operator to; the CI runs it before publishing. The three change
  together.

## Testing

Functional tests extend `WebTestCase`; handler and service tests extend
`KernelTestCase` against a real PostgreSQL database, every test rolled back
by `dama/doctrine-test-bundle`. Sequences are not rolled back, so a test
never assumes an id. The CI runs the suite on PostgreSQL 17 and on 18.

- Tests own their data: handler tests build it with `AccessFixtures` and
  `IssueFixtures` under the small configuration of
  `tests/Config/Fixture/DefinitionFactory`
  (`KernelHelpers::applyConfiguration()`); page and component tests load
  `tests/Scene/ShopScene`. API tests extend `tests/Api/ApiTestCase`, MCP
  tests `tests/Api/Mcp/McpTestCase`. The test environment has no identity
  provider: `tests/Security/SingleSignOnEnvironment` gives a test one
  (before the kernel boots), `SingleSignOnProvider` also answers as the
  provider. The development story is loaded only
  by the tests that compare a PHP rule with its SQL, and by `AppStoryTest`.
- `castor test` runs the suite in parallel (ParaTest), each process with
  its own database, folders, cache pool and locks: a test that touches
  anything else shared between processes, a file under `var/` above all,
  will fail one run in ten. With arguments PHPUnit runs alone (`castor test
  --filter IssueListTest`).
- The Storia snapshots run with the suite; after a deliberate change of a
  component, review the diff before `castor qa:storia --update`.
- A feature isn't done until it has a test that exercises it the way a
  caller would: an HTTP request for a controller, a command or query
  dispatched on its bus for a handler. Not just "it didn't throw".

## Quality tools

`castor qa` runs every check the CI runs (`.github/workflows/ci.yaml`) and
must pass before handing work over: `castor cs` (php-cs-fixer, `@Symfony`
and `@Symfony:risky` plus `declare(strict_types=1)`), `castor phpstan`
(level 8, Symfony and Doctrine extensions), `castor lint`
(`lint:container`, `lint:twig`, `lint:yaml`), `castor lsp` (Symfony
Language Tools: routes, services, templates, translation keys), `castor
qa:migrations` (the dev database ran them all, and they give the schema of
the entities) and `castor test` (PHPUnit, Storia snapshots included). The
CI also builds the assets (`npm ci && npm run build`).
