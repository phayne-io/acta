# Adding a context

A context is added in five steps, each with its generator. The generators write a
starting point, not a finished feature: documented classes in the right layer,
registered where they must be, and tests — some of which stay red until you have
written the business rule. `TODO` comments mark what is yours to decide.

They never overwrite a file, and they change existing files in one way only: by adding
lines above marker comments such as `// acta:events`. When a marker is gone, the
generator prints what to add by hand.

The templates are in `templates/make`. They are part of your project: change them to
make the generators write code the way your team writes it.

## 1. The context

```sh
bin/console make:context Billing
```

- `src/Billing/ConfigProvider.php` — the table of contents of the context, empty for now
- `src/Billing/Domain/Exception/BillingRuleViolated.php` — the parent of its business exceptions
- `src/Billing/Infrastructure/Primary/Http/BusinessRuleMapper.php` — reports them as 422
- registers the context in `config/config.php` and adds its tag to the contract

## 2. An aggregate

```sh
bin/console make:aggregate Billing Invoice
```

- `Domain/Invoice.php`, `Domain/InvoiceId.php` — the aggregate and its identifier
- `Domain/Event/InvoiceCreated.php` — its first event
- `Domain/InvoiceRepository.php` — the port; `Infrastructure/Secondary/Persistence/EventSourcedInvoiceRepository.php` — the adapter
- `tests/Billing/Domain/InvoiceTest.php` — a given/when/then test, green

Then model it: rename `create()` after what the business calls it, give it what an
invoice needs to exist, and keep the state the rules will need in `apply()`.

## 3. A use case that changes it

```sh
bin/console make:command Billing IssueInvoice --event InvoiceIssued
```

- `Application/Command/IssueInvoice.php` and `IssueInvoiceHandler.php`
- `Domain/Event/InvoiceIssued.php`
- `Infrastructure/Primary/Http/IssueInvoiceAction.php` — the request handler of the new
  `issueInvoice` operation, `POST /invoices/{invoiceId}/issue-invoice`, added to the contract
- `Infrastructure/Primary/Console/IssueInvoiceCommand.php` — `bin/console billing:issue-invoice`
- `tests/Billing/Application/IssueInvoiceHandlerTest.php` — **red**

Then, from the inside out:

1. Write the rule in the aggregate: a method that checks it and records the event, and
   the line of `apply()` that changes the state.

   ```php
   public function issue(DateTimeImmutable $dueDate): void
   {
       $this->issued && throw AlreadyIssued::invoice($this->id);

       $this->recordThat(new InvoiceIssued($this->id, $dueDate));
   }
   ```

2. Call it from the handler, in place of the `LogicException`.
3. Describe the request in the contract — its body, its responses — and give the
   command the same fields: they are filled by name from the path, the query and the
   body. Rename the path if the generated one does not read well.
4. Make the test pass, then assert what the use case must refuse.

Add `--aggregate` when the context has several aggregates.

## 4. A use case that reads

```sh
bin/console make:query Billing GetInvoice
```

- `Application/Query/GetInvoice.php` and `GetInvoiceHandler.php`
- the read model of the aggregate: `Application/Query/InvoiceView.php` (what readers
  get), `InvoiceDirectory.php` (the port), and in `Infrastructure/Secondary/Persistence`
  its PostgreSQL and in-memory adapters and `InvoiceDirectoryProjection.php`, which
  fills it from the events
- `Infrastructure/Primary/Http/GetInvoiceAction.php` — the request handler of the new
  `getInvoice` operation, `GET /invoices/{invoiceId}`, with its `Invoice` schema
- `tests/Billing/Infrastructure/InvoiceDirectoryProjectionTest.php` — green

Then give the view what readers need, and the three places that describe it the same
fields: the `Invoice` schema of the contract, the table in `PostgresInvoiceDirectory`
and the handlers of the projection. Create the table and start the projection:

```sh
bin/console es:migrate
bin/console projection:run billing_invoices
```

A second query about the same aggregate reuses its read model and only adds what is its
own.

## 5. Webhooks from a third party

```sh
bin/console make:webhook Billing stripe
```

- `Infrastructure/Primary/Webhook/StripeWebhookSource.php` — the name of the source, how
  its signature is verified and with which secret (`WEBHOOK_STRIPE_SECRET`)
- `Infrastructure/Primary/Webhook/StripeTranslator.php` — turns what the sender says
  into commands of the context
- `tests/Billing/Infrastructure/StripeTranslatorTest.php` — **red**

Webhooks are then received at `POST /webhooks/stripe`. Check the signature scheme
against the sender's documentation: the generated source uses Standard Webhooks;
`HmacSignature` covers the senders that sign in a single header.

## What the generators do not write

**Webhooks to your own subscribers.** Publishing an event is a decision about your
public vocabulary, so it is written by hand: implement `IntegrationEventMapper` in
`Infrastructure/Secondary/Webhook`, list it under `integration_events` in the
`ConfigProvider`, and describe each event type in the `webhooks` section of the
contract. `src/Banking/Infrastructure/Secondary/Webhook/PublicEvents.php` is the model.

**Talking to another context.** React to its integration events, or declare a port in
your application and implement it in your infrastructure by calling the other context's
buses. Never use its domain classes: `ContextIsolationTest` would fail.

## Before you commit

```sh
composer cs:fix && composer check
```
