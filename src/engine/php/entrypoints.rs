use std::path::{Path, PathBuf};

use mago_database::file::File;
use mago_names::ResolvedNames;
use mago_span::{HasSpan, Span};
use mago_syntax::ast::{
    Argument, ArgumentList, AttributeList, Call, Class, ClassLikeMember, ClassLikeMemberSelector,
    Enum, Expression, Extends, Identifier, Implements, Interface, Literal, Method, Namespace,
    NamespaceBody, Program, Property, Statement, StaticMethodCall, Trait,
};

use crate::engine::{EntryPoint, EntryPointSource};

// FQNs of the Symfony attributes we recognise.
const ROUTE_FQNS: &[&str] = &[
    "Symfony\\Component\\Routing\\Attribute\\Route",
    "Symfony\\Component\\Routing\\Annotation\\Route",
];
const AS_COMMAND_FQN: &str = "Symfony\\Component\\Console\\Attribute\\AsCommand";
const AS_MESSAGE_HANDLER_FQN: &str = "Symfony\\Component\\Messenger\\Attribute\\AsMessageHandler";
const AS_CRON_TASK_FQN: &str = "Symfony\\Component\\Scheduler\\Attribute\\AsCronTask";
const AS_PERIODIC_TASK_FQN: &str = "Symfony\\Component\\Scheduler\\Attribute\\AsPeriodicTask";
const AS_EVENT_LISTENER_FQN: &str = "Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener";
const AS_SCHEDULE_FQN: &str = "Symfony\\Component\\Scheduler\\Attribute\\AsSchedule";

// Marker interfaces / base classes for source-level (non-attribute) detection.
const SF_COMMAND_BASE_FQN: &str = "Symfony\\Component\\Console\\Command\\Command";
const SF_MESSAGE_HANDLER_INTERFACE_FQN: &str = "Symfony\\Component\\Messenger\\Handler\\MessageHandlerInterface";
const SF_EVENT_SUBSCRIBER_INTERFACE_FQN: &str = "Symfony\\Component\\EventDispatcher\\EventSubscriberInterface";
const SF_SCHEDULE_PROVIDER_INTERFACE_FQN: &str = "Symfony\\Component\\Scheduler\\ScheduleProviderInterface";

// Laravel marker interfaces / base classes.
const LV_COMMAND_BASE_FQN: &str = "Illuminate\\Console\\Command";
const LV_SHOULD_QUEUE_FQN: &str = "Illuminate\\Contracts\\Queue\\ShouldQueue";
const LV_SHOULD_QUEUE_AFTER_COMMIT_FQN: &str = "Illuminate\\Contracts\\Queue\\ShouldQueueAfterCommit";

// PHPUnit. Detected when a class extends `TestCase` directly OR when its
// name ends with `Test` (the conventional class-name suffix). Methods are
// entry points if their name starts with `test` (case-insensitive) OR they
// carry `#[Test]`.
const PHPUNIT_TESTCASE_FQN: &str = "PHPUnit\\Framework\\TestCase";
const PHPUNIT_TEST_ATTR_FQN: &str = "PHPUnit\\Framework\\Attributes\\Test";

// Laravel route facade FQN (resolved from `Route::get(...)` etc).
const LV_ROUTE_FACADE_FQN: &str = "Illuminate\\Support\\Facades\\Route";
const LV_ARTISAN_FACADE_FQN: &str = "Illuminate\\Support\\Facades\\Artisan";
const LV_SCHEDULE_FACADE_FQN: &str = "Illuminate\\Support\\Facades\\Schedule";

const KIND_ROUTE: &str = "symfony.route";
const KIND_COMMAND: &str = "symfony.command";
const KIND_MESSAGE_HANDLER: &str = "symfony.message_handler";
const KIND_CRON_TASK: &str = "symfony.cron_task";
const KIND_PERIODIC_TASK: &str = "symfony.periodic_task";
const KIND_EVENT_LISTENER: &str = "symfony.event_listener";
const KIND_SCHEDULE_PROVIDER: &str = "symfony.schedule_provider";

const KIND_LV_ROUTE: &str = "laravel.route";
const KIND_LV_COMMAND: &str = "laravel.command";
const KIND_LV_JOB: &str = "laravel.job";
const KIND_LV_LISTENER: &str = "laravel.listener";
const KIND_LV_SCHEDULED_TASK: &str = "laravel.scheduled_task";

const KIND_PHPUNIT_TEST: &str = "phpunit.test";

const ROUTE_HTTP_METHODS: &[&str] = &[
    "get", "post", "put", "patch", "delete", "options", "any", "match",
];
const ROUTE_RESOURCE_METHODS: &[&str] = &["resource", "apiResource", "singleton", "apiSingleton"];
const ROUTE_TERMINAL_METHODS: &[&str] = &["redirect", "permanentRedirect", "view"];

pub fn extract<'arena>(
    program: &'arena Program<'arena>,
    resolved_names: &ResolvedNames<'arena>,
    file: &File,
    abs_path: &Path,
) -> Vec<EntryPoint> {
    let mut ctx = Ctx { resolved_names, file, abs_path: abs_path.to_path_buf(), namespace: String::new(), out: Vec::new() };
    for stmt in program.statements.iter() {
        visit_statement(stmt, &mut ctx);
    }
    ctx.out
}

struct Ctx<'a, 'arena> {
    resolved_names: &'a ResolvedNames<'arena>,
    file: &'a File,
    abs_path: PathBuf,
    namespace: String,
    out: Vec<EntryPoint>,
}

fn visit_statement<'arena>(stmt: &'arena Statement<'arena>, ctx: &mut Ctx<'_, 'arena>) {
    match stmt {
        Statement::Namespace(ns) => visit_namespace(ns, ctx),
        Statement::Class(c) => visit_class(c, ctx),
        Statement::Interface(i) => visit_interface(i, ctx),
        Statement::Trait(t) => visit_trait(t, ctx),
        Statement::Enum(e) => visit_enum(e, ctx),
        Statement::Expression(es) => visit_top_level_expression(es.expression, ctx),
        _ => {}
    }
}

/// Walk a top-level expression statement looking for Laravel facade calls.
/// Routes/console/channels typically chain method calls — we drill through
/// the chain to find the originating static-method call.
fn visit_top_level_expression<'arena>(expr: &'arena Expression<'arena>, ctx: &mut Ctx<'_, 'arena>) {
    if let Some(call) = leaf_static_call(expr) {
        let class_fqn_opt = match call.class {
            Expression::Identifier(id) => resolve_identifier(id, ctx.resolved_names).map(|s| s.to_string()),
            _ => None,
        };
        if let Some(class_fqn) = class_fqn_opt
            && let ClassLikeMemberSelector::Identifier(method_id) = &call.method
        {
            let method = method_id.value;
            if class_fqn == LV_ROUTE_FACADE_FQN {
                emit_laravel_route(&class_fqn, method, &call.argument_list, call.span().start.offset, ctx);
            } else if class_fqn == LV_SCHEDULE_FACADE_FQN {
                emit_laravel_schedule(method, &call.argument_list, call.span().start.offset, ctx);
            } else if class_fqn == LV_ARTISAN_FACADE_FQN && method.eq_ignore_ascii_case("command") {
                emit_laravel_closure_command(&call.argument_list, call.span().start.offset, ctx);
            }
        }
    }
    // Recurse into closure arguments anywhere in the expression to catch
    // nested `Route::group(function () { Route::get(...); ... })`.
    walk_closures_in_expr(expr, ctx);
}

fn walk_closures_in_expr<'arena>(expr: &'arena Expression<'arena>, ctx: &mut Ctx<'_, 'arena>) {
    use mago_syntax::ast::{ArrowFunction, Closure};

    fn walk_args<'arena>(args: &'arena ArgumentList<'arena>, ctx: &mut Ctx<'_, 'arena>) {
        for arg in args.arguments.iter() {
            let value = match arg {
                Argument::Positional(p) => p.value,
                Argument::Named(n) => n.value,
            };
            walk_closures_in_expr(value, ctx);
        }
    }

    fn walk_closure<'arena>(c: &'arena Closure<'arena>, ctx: &mut Ctx<'_, 'arena>) {
        for stmt in c.body.statements.iter() {
            if let Statement::Expression(es) = stmt {
                visit_top_level_expression(es.expression, ctx);
            }
        }
    }

    fn walk_arrow<'arena>(af: &'arena ArrowFunction<'arena>, ctx: &mut Ctx<'_, 'arena>) {
        visit_top_level_expression(af.expression, ctx);
    }

    match expr {
        Expression::Closure(c) => walk_closure(c, ctx),
        Expression::ArrowFunction(af) => walk_arrow(af, ctx),
        Expression::Call(call) => match call {
            Call::StaticMethod(sm) => walk_args(&sm.argument_list, ctx),
            Call::Method(m) => {
                walk_closures_in_expr(m.object, ctx);
                walk_args(&m.argument_list, ctx);
            }
            Call::NullSafeMethod(m) => {
                walk_closures_in_expr(m.object, ctx);
                walk_args(&m.argument_list, ctx);
            }
            Call::Function(f) => {
                walk_closures_in_expr(f.function, ctx);
                walk_args(&f.argument_list, ctx);
            }
        },
        _ => {}
    }
}

fn leaf_static_call<'arena>(expr: &'arena Expression<'arena>) -> Option<&'arena StaticMethodCall<'arena>> {
    // Drill through method-call chains (`Route::get(...)->name(...)->middleware(...)`)
    // until we hit the originating `Route::get(...)` call.
    let mut current = expr;
    loop {
        match current {
            Expression::Call(call) => match call {
                Call::StaticMethod(sm) => return Some(sm),
                Call::Method(m) => current = m.object,
                Call::NullSafeMethod(m) => current = m.object,
                _ => return None,
            },
            _ => return None,
        }
    }
}

fn emit_laravel_route<'arena>(
    facade_fqn: &str,
    method: &str,
    args: &'arena ArgumentList<'arena>,
    offset: u32,
    ctx: &mut Ctx<'_, 'arena>,
) {
    let _ = facade_fqn;
    let mlow = method.to_ascii_lowercase();
    let line = ctx.file.line_number(offset) + 1;
    let path = ctx.abs_path.clone();

    if ROUTE_HTTP_METHODS.contains(&mlow.as_str()) {
        let uri = first_positional_string(args).map(|s| s.to_string()).unwrap_or_default();
        let handler = extract_laravel_handler(args, ctx.resolved_names)
            .unwrap_or_else(|| "<closure>".to_string());
        let methods = if mlow == "any" {
            vec!["ANY".to_string()]
        } else if mlow == "match" {
            extract_match_methods(args).unwrap_or_else(|| vec!["GET".to_string()])
        } else {
            vec![mlow.to_uppercase()]
        };
        ctx.out.push(EntryPoint {
            kind: KIND_LV_ROUTE.to_string(),
            name: handler.clone(),
            handler_fqn: handler,
            handler_path: path,
            handler_line: line,
            source: EntryPointSource::StaticCall,
            extra: serde_json::json!({ "path": uri, "methods": methods }),
        });
    } else if ROUTE_RESOURCE_METHODS.contains(&method) {
        // Resource: name + controller class. v0.2 emits a single entry per
        // resource (not the seven sub-routes); a follow-up phase can expand.
        let name = first_positional_string(args).map(|s| s.to_string()).unwrap_or_default();
        let handler =
            second_positional_class(args, ctx.resolved_names).unwrap_or_else(|| "<resource>".to_string());
        ctx.out.push(EntryPoint {
            kind: KIND_LV_ROUTE.to_string(),
            name: format!("{} ({})", name, method),
            handler_fqn: handler,
            handler_path: path,
            handler_line: line,
            source: EntryPointSource::StaticCall,
            extra: serde_json::json!({ "path": name, "kind": method }),
        });
    } else if ROUTE_TERMINAL_METHODS.contains(&method) {
        let uri = first_positional_string(args).map(|s| s.to_string()).unwrap_or_default();
        ctx.out.push(EntryPoint {
            kind: KIND_LV_ROUTE.to_string(),
            name: format!("{} {}", method, uri),
            handler_fqn: format!("<{}>", method),
            handler_path: path,
            handler_line: line,
            source: EntryPointSource::StaticCall,
            extra: serde_json::json!({ "path": uri, "kind": method }),
        });
    }
}

fn emit_laravel_schedule<'arena>(
    method: &str,
    args: &'arena ArgumentList<'arena>,
    offset: u32,
    ctx: &mut Ctx<'_, 'arena>,
) {
    let line = ctx.file.line_number(offset) + 1;
    let path = ctx.abs_path.clone();
    match method {
        "command" => {
            let cmd = first_positional_string(args).map(|s| s.to_string()).unwrap_or_default();
            ctx.out.push(EntryPoint {
                kind: KIND_LV_SCHEDULED_TASK.to_string(),
                name: format!("command: {}", cmd),
                handler_fqn: cmd.clone(),
                handler_path: path,
                handler_line: line,
                source: EntryPointSource::StaticCall,
                extra: serde_json::json!({ "kind": "command", "command": cmd }),
            });
        }
        "job" => {
            let job_class =
                first_positional_class(args, ctx.resolved_names).unwrap_or_else(|| "<unknown>".to_string());
            ctx.out.push(EntryPoint {
                kind: KIND_LV_SCHEDULED_TASK.to_string(),
                name: format!("job: {}", job_class),
                handler_fqn: format!("{}::handle", job_class),
                handler_path: path,
                handler_line: line,
                source: EntryPointSource::StaticCall,
                extra: serde_json::json!({ "kind": "job", "job": job_class }),
            });
        }
        "call" => {
            ctx.out.push(EntryPoint {
                kind: KIND_LV_SCHEDULED_TASK.to_string(),
                name: "call: <closure>".to_string(),
                handler_fqn: "<closure>".to_string(),
                handler_path: path,
                handler_line: line,
                source: EntryPointSource::StaticCall,
                extra: serde_json::json!({ "kind": "call" }),
            });
        }
        _ => {}
    }
}

fn emit_laravel_closure_command<'arena>(
    args: &'arena ArgumentList<'arena>,
    offset: u32,
    ctx: &mut Ctx<'_, 'arena>,
) {
    let line = ctx.file.line_number(offset) + 1;
    let path = ctx.abs_path.clone();
    let signature = first_positional_string(args).map(|s| s.to_string()).unwrap_or_default();
    let cmd_name = signature.split_whitespace().next().unwrap_or("").to_string();
    ctx.out.push(EntryPoint {
        kind: KIND_LV_COMMAND.to_string(),
        name: cmd_name,
        handler_fqn: "<closure>".to_string(),
        handler_path: path,
        handler_line: line,
        source: EntryPointSource::StaticCall,
        extra: serde_json::Value::Null,
    });
}

fn extract_laravel_handler<'arena>(
    args: &'arena ArgumentList<'arena>,
    names: &ResolvedNames<'arena>,
) -> Option<String> {
    let mut iter = args.arguments.iter().filter(|a| matches!(a, Argument::Positional(_)));
    let _first = iter.next();
    let action = iter.next()?;
    match action {
        Argument::Positional(p) => match p.value {
            Expression::Array(arr) => extract_array_handler(arr, names),
            Expression::Literal(Literal::String(s)) => s.value.map(|raw| {
                if let Some((c, m)) = raw.split_once('@') {
                    format!("{}::{}", c, m)
                } else {
                    raw.to_string()
                }
            }),
            Expression::Access(mago_syntax::ast::Access::ClassConstant(cca)) => {
                resolve_class_const_access(cca, names).map(|fqn| format!("{}::__invoke", fqn))
            }
            _ => None,
        },
        _ => None,
    }
}

fn extract_array_handler<'arena>(
    arr: &'arena mago_syntax::ast::Array<'arena>,
    names: &ResolvedNames<'arena>,
) -> Option<String> {
    let mut elems = arr.elements.iter().filter_map(|el| match el {
        mago_syntax::ast::ArrayElement::Value(v) => Some(v.value),
        _ => None,
    });
    let class_expr = elems.next()?;
    let method_expr = elems.next()?;
    let class_name = match class_expr {
        Expression::Access(mago_syntax::ast::Access::ClassConstant(cca)) => {
            resolve_class_const_access(cca, names)?
        }
        Expression::Literal(Literal::String(s)) => s.value?.to_string(),
        _ => return None,
    };
    let method_name = match method_expr {
        Expression::Literal(Literal::String(s)) => s.value?.to_string(),
        _ => return None,
    };
    Some(format!("{}::{}", class_name, method_name))
}

fn resolve_class_const_access<'arena>(
    cca: &'arena mago_syntax::ast::ClassConstantAccess<'arena>,
    names: &ResolvedNames<'arena>,
) -> Option<String> {
    if let Expression::Identifier(id) = cca.class {
        return resolve_identifier(id, names).map(|s| s.to_string());
    }
    None
}

fn extract_match_methods<'arena>(args: &'arena ArgumentList<'arena>) -> Option<Vec<String>> {
    let first = args.arguments.iter().find_map(|a| match a {
        Argument::Positional(p) => Some(p.value),
        _ => None,
    })?;
    if let Expression::Array(arr) = first {
        let mut out = Vec::new();
        for el in arr.elements.iter() {
            if let mago_syntax::ast::ArrayElement::Value(v) = el
                && let Some(s) = string_literal(v.value)
            {
                out.push(s.to_uppercase());
            }
        }
        if !out.is_empty() {
            return Some(out);
        }
    }
    None
}

fn second_positional_class<'arena>(
    args: &'arena ArgumentList<'arena>,
    names: &ResolvedNames<'arena>,
) -> Option<String> {
    let mut iter = args.arguments.iter().filter(|a| matches!(a, Argument::Positional(_)));
    iter.next();
    let second = iter.next()?;
    let Argument::Positional(p) = second else { return None };
    match p.value {
        Expression::Access(mago_syntax::ast::Access::ClassConstant(cca)) => {
            resolve_class_const_access(cca, names)
        }
        Expression::Literal(Literal::String(s)) => s.value.map(|s| s.to_string()),
        _ => None,
    }
}

fn first_positional_class<'arena>(
    args: &'arena ArgumentList<'arena>,
    names: &ResolvedNames<'arena>,
) -> Option<String> {
    let arg = args.arguments.iter().find(|a| matches!(a, Argument::Positional(_)))?;
    let Argument::Positional(p) = arg else { return None };
    match p.value {
        Expression::Instantiation(inst) => match inst.class {
            Expression::Identifier(id) => resolve_identifier(id, names).map(|s| s.to_string()),
            _ => None,
        },
        Expression::Access(mago_syntax::ast::Access::ClassConstant(cca)) => {
            resolve_class_const_access(cca, names)
        }
        _ => None,
    }
}

fn visit_namespace<'arena>(ns: &'arena Namespace<'arena>, ctx: &mut Ctx<'_, 'arena>) {
    let prev = std::mem::take(&mut ctx.namespace);
    ctx.namespace = ns.name.as_ref().map(identifier_text).unwrap_or_default().to_string();
    match &ns.body {
        NamespaceBody::Implicit(body) => {
            for stmt in body.statements.iter() {
                visit_statement(stmt, ctx);
            }
        }
        NamespaceBody::BraceDelimited(block) => {
            for stmt in block.statements.iter() {
                visit_statement(stmt, ctx);
            }
        }
    }
    ctx.namespace = prev;
}

fn visit_class<'arena>(c: &'arena Class<'arena>, ctx: &mut Ctx<'_, 'arena>) {
    let class_fqn = qualify(&ctx.namespace, c.name.value);
    visit_class_like_attribute_lists(&class_fqn, &c.attribute_lists, c.name.span, ctx);
    detect_interface_entry_points(&class_fqn, c, ctx);
    visit_members(&class_fqn, c.members.iter(), ctx);
}

fn visit_interface<'arena>(i: &'arena Interface<'arena>, ctx: &mut Ctx<'_, 'arena>) {
    let class_fqn = qualify(&ctx.namespace, i.name.value);
    visit_class_like_attribute_lists(&class_fqn, &i.attribute_lists, i.name.span, ctx);
    visit_members(&class_fqn, i.members.iter(), ctx);
}

fn visit_trait<'arena>(t: &'arena Trait<'arena>, ctx: &mut Ctx<'_, 'arena>) {
    let class_fqn = qualify(&ctx.namespace, t.name.value);
    visit_class_like_attribute_lists(&class_fqn, &t.attribute_lists, t.name.span, ctx);
    visit_members(&class_fqn, t.members.iter(), ctx);
}

fn visit_enum<'arena>(e: &'arena Enum<'arena>, ctx: &mut Ctx<'_, 'arena>) {
    let class_fqn = qualify(&ctx.namespace, e.name.value);
    visit_class_like_attribute_lists(&class_fqn, &e.attribute_lists, e.name.span, ctx);
    visit_members(&class_fqn, e.members.iter(), ctx);
}

fn visit_members<'arena, I>(class_fqn: &str, members: I, ctx: &mut Ctx<'_, 'arena>)
where
    I: Iterator<Item = &'arena ClassLikeMember<'arena>>,
{
    for member in members {
        if let ClassLikeMember::Method(m) = member {
            visit_method(class_fqn, m, ctx);
        }
    }
}

/// Class-level attributes apply to a "default" handler: `execute` for AsCommand,
/// `__invoke` for the others. Override via attribute's `method:` arg when present.
fn visit_class_like_attribute_lists<'arena>(
    class_fqn: &str,
    attribute_lists: &'arena mago_syntax::ast::Sequence<'arena, AttributeList<'arena>>,
    name_span: Span,
    ctx: &mut Ctx<'_, 'arena>,
) {
    for list in attribute_lists.iter() {
        for attr in list.attributes.iter() {
            let Some(fqn) = resolve_identifier(&attr.name, ctx.resolved_names) else { continue };
            let Some((kind, default_handler)) = match_class_attr(fqn) else { continue };

            let handler_method =
                attr.argument_list.as_ref().and_then(|al| named_string_arg(al, "method")).unwrap_or(default_handler);
            let handler_fqn = format!("{}::{}", class_fqn, handler_method);

            let name = derive_entry_point_name(kind, &handler_fqn, attr.argument_list.as_ref());
            let extra = derive_extra(kind, attr.argument_list.as_ref());

            let (path, line) = locate(name_span, ctx);
            ctx.out.push(EntryPoint {
                kind: kind.to_string(),
                name,
                handler_fqn,
                handler_path: path,
                handler_line: line,
                source: EntryPointSource::Attribute,
                extra,
            });
        }
    }
}

/// Detect entry points based on the class's `extends` / `implements` clauses
/// (no attribute required). Runs alongside the attribute walker; both can
/// emit entries for the same class — de-duplication happens later by handler
/// FQN.
fn detect_interface_entry_points<'arena>(
    class_fqn: &str,
    c: &'arena Class<'arena>,
    ctx: &mut Ctx<'_, 'arena>,
) {
    let parents = parent_fqns(c.extends.as_ref(), ctx.resolved_names);
    let interfaces = implements_fqns(c.implements.as_ref(), ctx.resolved_names);
    let (path, line) = locate(c.name.span, ctx);

    // ---- Laravel ----
    if parents.iter().any(|p| p == LV_COMMAND_BASE_FQN) {
        let cmd_name = find_signature_property(&c.members)
            .map(|sig| extract_command_name(&sig).unwrap_or(sig))
            .unwrap_or_else(|| format!("{}::handle", class_fqn));
        ctx.out.push(EntryPoint {
            kind: KIND_LV_COMMAND.to_string(),
            name: cmd_name,
            handler_fqn: format!("{}::handle", class_fqn),
            handler_path: path.clone(),
            handler_line: line,
            source: EntryPointSource::Interface,
            extra: serde_json::Value::Null,
        });
    }

    if interfaces
        .iter()
        .any(|i| i == LV_SHOULD_QUEUE_FQN || i == LV_SHOULD_QUEUE_AFTER_COMMIT_FQN)
    {
        ctx.out.push(EntryPoint {
            kind: KIND_LV_JOB.to_string(),
            name: format!("{}::handle", class_fqn),
            handler_fqn: format!("{}::handle", class_fqn),
            handler_path: path.clone(),
            handler_line: line,
            source: EntryPointSource::Interface,
            extra: serde_json::Value::Null,
        });
    }

    if class_fqn.starts_with("App\\Listeners\\") {
        // Laravel auto-discovery convention. Find a `handle(EventClass)` method.
        for member in c.members.iter() {
            let ClassLikeMember::Method(m) = member else { continue };
            if m.name.value.eq_ignore_ascii_case("handle") {
                let event_hint = first_param_type_hint(m, ctx.resolved_names)
                    .unwrap_or_else(|| "?".to_string());
                ctx.out.push(EntryPoint {
                    kind: KIND_LV_LISTENER.to_string(),
                    name: format!("{}::handle", class_fqn),
                    handler_fqn: format!("{}::handle", class_fqn),
                    handler_path: path.clone(),
                    handler_line: line,
                    source: EntryPointSource::Interface,
                    extra: serde_json::json!({ "event": event_hint }),
                });
                break;
            }
        }
    }

    // ---- Symfony ----
    if parents.iter().any(|p| p == SF_COMMAND_BASE_FQN) {
        // Scan for `protected static $defaultName = '...';` first; fall back
        // to handler_fqn if not found. setName() inside configure() requires
        // walking method bodies — defer.
        let cmd_name =
            find_default_name_property(&c.members).unwrap_or_else(|| format!("{}::execute", class_fqn));
        ctx.out.push(EntryPoint {
            kind: KIND_COMMAND.to_string(),
            name: cmd_name,
            handler_fqn: format!("{}::execute", class_fqn),
            handler_path: path.clone(),
            handler_line: line,
            source: EntryPointSource::Interface,
            extra: serde_json::Value::Null,
        });
    }

    if interfaces.iter().any(|i| i == SF_MESSAGE_HANDLER_INTERFACE_FQN) {
        ctx.out.push(EntryPoint {
            kind: KIND_MESSAGE_HANDLER.to_string(),
            name: format!("{}::__invoke", class_fqn),
            handler_fqn: format!("{}::__invoke", class_fqn),
            handler_path: path.clone(),
            handler_line: line,
            source: EntryPointSource::Interface,
            extra: serde_json::Value::Null,
        });
    }

    if interfaces.iter().any(|i| i == SF_EVENT_SUBSCRIBER_INTERFACE_FQN) {
        // For v0.2 we report the class as a single event-listener entry
        // point with handler = getSubscribedEvents. Per-method extraction
        // (parsing the static method's array literal) lands later.
        ctx.out.push(EntryPoint {
            kind: KIND_EVENT_LISTENER.to_string(),
            name: format!("{}::getSubscribedEvents", class_fqn),
            handler_fqn: format!("{}::getSubscribedEvents", class_fqn),
            handler_path: path.clone(),
            handler_line: line,
            source: EntryPointSource::Interface,
            extra: serde_json::Value::Null,
        });
    }

    if interfaces.iter().any(|i| i == SF_SCHEDULE_PROVIDER_INTERFACE_FQN) {
        ctx.out.push(EntryPoint {
            kind: KIND_SCHEDULE_PROVIDER.to_string(),
            name: format!("{}::getSchedule", class_fqn),
            handler_fqn: format!("{}::getSchedule", class_fqn),
            handler_path: path.clone(),
            handler_line: line,
            source: EntryPointSource::Interface,
            extra: serde_json::Value::Null,
        });
    }

    // ---- PHPUnit ----
    let class_short_name = class_fqn.rsplit('\\').next().unwrap_or(class_fqn);
    let is_test_class = parents.iter().any(|p| p == PHPUNIT_TESTCASE_FQN)
        || class_short_name.ends_with("Test");
    if is_test_class {
        for member in c.members.iter() {
            let ClassLikeMember::Method(m) = member else { continue };
            let mname = m.name.value;
            let has_test_prefix = mname.len() >= 4
                && mname[..4].eq_ignore_ascii_case("test")
                && (mname.len() == 4 || mname.as_bytes()[4].is_ascii_uppercase());
            let has_test_attr = method_has_attribute(m, ctx.resolved_names, PHPUNIT_TEST_ATTR_FQN);
            if !has_test_prefix && !has_test_attr {
                continue;
            }
            let (m_path, m_line) = locate(m.name.span, ctx);
            ctx.out.push(EntryPoint {
                kind: KIND_PHPUNIT_TEST.to_string(),
                name: format!("{}::{}", class_fqn, mname),
                handler_fqn: format!("{}::{}", class_fqn, mname),
                handler_path: m_path,
                handler_line: m_line,
                source: if has_test_attr { EntryPointSource::Attribute } else { EntryPointSource::Interface },
                extra: serde_json::Value::Null,
            });
        }
    }
}

fn method_has_attribute<'arena>(
    m: &'arena Method<'arena>,
    names: &ResolvedNames<'arena>,
    target_fqn: &str,
) -> bool {
    for list in m.attribute_lists.iter() {
        for attr in list.attributes.iter() {
            if let Some(fqn) = resolve_identifier(&attr.name, names)
                && fqn == target_fqn
            {
                return true;
            }
        }
    }
    false
}

fn parent_fqns<'a, 'arena>(
    extends: Option<&'a Extends<'arena>>,
    names: &'a ResolvedNames<'arena>,
) -> Vec<String> {
    let Some(ext) = extends else { return Vec::new() };
    ext.types
        .iter()
        .filter_map(|id| resolve_identifier(id, names).map(|s| s.to_string()))
        .collect()
}

fn implements_fqns<'a, 'arena>(
    implements: Option<&'a Implements<'arena>>,
    names: &'a ResolvedNames<'arena>,
) -> Vec<String> {
    let Some(imp) = implements else { return Vec::new() };
    imp.types
        .iter()
        .filter_map(|id| resolve_identifier(id, names).map(|s| s.to_string()))
        .collect()
}

fn find_default_name_property<'arena>(
    members: &'arena mago_syntax::ast::Sequence<'arena, ClassLikeMember<'arena>>,
) -> Option<String> {
    find_property_string(members, "$defaultName")
}

fn find_signature_property<'arena>(
    members: &'arena mago_syntax::ast::Sequence<'arena, ClassLikeMember<'arena>>,
) -> Option<String> {
    find_property_string(members, "$signature")
}

fn find_property_string<'arena>(
    members: &'arena mago_syntax::ast::Sequence<'arena, ClassLikeMember<'arena>>,
    name: &str,
) -> Option<String> {
    for member in members.iter() {
        let ClassLikeMember::Property(prop) = member else { continue };
        let Property::Plain(plain) = prop else { continue };
        for item in plain.items.iter() {
            let mago_syntax::ast::PropertyItem::Concrete(concrete) = item else { continue };
            let var_name = concrete.variable.name;
            if var_name.eq_ignore_ascii_case(name)
                && let Some(s) = string_literal(concrete.value)
            {
                return Some(s.to_string());
            }
        }
    }
    None
}

/// Laravel's `$signature` is `'name {arg} {--option}'`. Extract just the
/// command name (the first whitespace-delimited token).
fn extract_command_name(signature: &str) -> Option<String> {
    signature.split_whitespace().next().map(|s| s.to_string())
}

/// Read the type hint of a method's first parameter, resolved to FQN.
/// Used to surface the listener's event class.
fn first_param_type_hint<'arena>(
    method: &'arena Method<'arena>,
    names: &ResolvedNames<'arena>,
) -> Option<String> {
    let first = method.parameter_list.parameters.iter().next()?;
    let hint = first.hint.as_ref()?;
    use mago_syntax::ast::Hint;
    match hint {
        Hint::Identifier(id) => resolve_identifier(id, names).map(|s| s.to_string()),
        _ => None,
    }
}

fn visit_method<'arena>(class_fqn: &str, m: &'arena Method<'arena>, ctx: &mut Ctx<'_, 'arena>) {
    if m.attribute_lists.is_empty() {
        return;
    }
    let method_name = m.name.value;
    let handler_fqn = format!("{}::{}", class_fqn, method_name);
    let (path, line) = locate(m.name.span, ctx);

    for list in m.attribute_lists.iter() {
        for attr in list.attributes.iter() {
            let Some(fqn) = resolve_identifier(&attr.name, ctx.resolved_names) else { continue };
            let Some(kind) = match_method_attr(fqn) else { continue };

            let name = derive_entry_point_name(kind, &handler_fqn, attr.argument_list.as_ref());
            let extra = derive_extra(kind, attr.argument_list.as_ref());

            ctx.out.push(EntryPoint {
                kind: kind.to_string(),
                name,
                handler_fqn: handler_fqn.clone(),
                handler_path: path.clone(),
                handler_line: line,
                source: EntryPointSource::Attribute,
                extra,
            });
        }
    }
}

// ---- attribute matching ---------------------------------------------------

fn match_class_attr(fqn: &str) -> Option<(&'static str, &'static str)> {
    match fqn {
        AS_COMMAND_FQN => Some((KIND_COMMAND, "execute")),
        AS_MESSAGE_HANDLER_FQN => Some((KIND_MESSAGE_HANDLER, "__invoke")),
        AS_CRON_TASK_FQN => Some((KIND_CRON_TASK, "__invoke")),
        AS_PERIODIC_TASK_FQN => Some((KIND_PERIODIC_TASK, "__invoke")),
        AS_EVENT_LISTENER_FQN => Some((KIND_EVENT_LISTENER, "__invoke")),
        AS_SCHEDULE_FQN => Some((KIND_SCHEDULE_PROVIDER, "getSchedule")),
        _ => None,
    }
}

fn match_method_attr(fqn: &str) -> Option<&'static str> {
    if ROUTE_FQNS.contains(&fqn) {
        return Some(KIND_ROUTE);
    }
    match fqn {
        AS_CRON_TASK_FQN => Some(KIND_CRON_TASK),
        AS_PERIODIC_TASK_FQN => Some(KIND_PERIODIC_TASK),
        AS_EVENT_LISTENER_FQN => Some(KIND_EVENT_LISTENER),
        _ => None,
    }
}

fn derive_entry_point_name(kind: &str, handler_fqn: &str, args: Option<&ArgumentList>) -> String {
    if let Some(args) = args {
        // Only Route and Command have a true human-meaningful "name" argument.
        // Scheduler tasks expose their cron/frequency in `extra` instead.
        let candidate = match kind {
            KIND_ROUTE | KIND_COMMAND => named_string_arg(args, "name"),
            _ => None,
        };
        if let Some(s) = candidate {
            return s.to_string();
        }
    }
    handler_fqn.to_string()
}

fn derive_extra(kind: &str, args: Option<&ArgumentList>) -> serde_json::Value {
    let Some(args) = args else { return serde_json::Value::Null };
    match kind {
        KIND_ROUTE => {
            let mut obj = serde_json::Map::new();
            if let Some(path) = first_positional_string(args).or_else(|| named_string_arg(args, "path")) {
                obj.insert("path".to_string(), serde_json::Value::String(path.to_string()));
            }
            if let Some(methods) = named_string_array_arg(args, "methods") {
                obj.insert(
                    "methods".to_string(),
                    serde_json::Value::Array(methods.into_iter().map(serde_json::Value::String).collect()),
                );
            }
            if obj.is_empty() {
                serde_json::Value::Null
            } else {
                serde_json::Value::Object(obj)
            }
        }
        KIND_CRON_TASK => match first_positional_string(args) {
            Some(expr) => serde_json::json!({ "expression": expr }),
            None => serde_json::Value::Null,
        },
        KIND_PERIODIC_TASK => match named_string_arg(args, "frequency").or_else(|| first_positional_string(args)) {
            Some(freq) => serde_json::json!({ "frequency": freq }),
            None => serde_json::Value::Null,
        },
        _ => serde_json::Value::Null,
    }
}

// ---- argument-literal helpers ---------------------------------------------

fn first_positional_string<'a>(args: &'a ArgumentList<'a>) -> Option<&'a str> {
    args.arguments.iter().find_map(|arg| match arg {
        Argument::Positional(p) => string_literal(p.value),
        _ => None,
    })
}

fn named_string_arg<'a>(args: &'a ArgumentList<'a>, key: &str) -> Option<&'a str> {
    args.arguments.iter().find_map(|arg| match arg {
        Argument::Named(n) if n.name.value.eq_ignore_ascii_case(key) => string_literal(n.value),
        _ => None,
    })
}

fn named_string_array_arg<'a>(args: &'a ArgumentList<'a>, key: &str) -> Option<Vec<String>> {
    let Some(Argument::Named(n)) = args.arguments.iter().find(|arg| {
        matches!(arg, Argument::Named(n) if n.name.value.eq_ignore_ascii_case(key))
    }) else {
        return None;
    };
    let array = match n.value {
        Expression::Array(a) => a,
        _ => return None,
    };
    let mut out = Vec::new();
    for elem in array.elements.iter() {
        if let mago_syntax::ast::ArrayElement::Value(v) = elem
            && let Some(s) = string_literal(v.value)
        {
            out.push(s.to_string());
        }
    }
    if out.is_empty() { None } else { Some(out) }
}

fn string_literal<'a>(expr: &'a Expression<'a>) -> Option<&'a str> {
    match expr {
        Expression::Literal(Literal::String(s)) => s.value,
        _ => None,
    }
}

// ---- name resolution + span helpers ---------------------------------------

fn resolve_identifier<'a, 'arena>(id: &'a Identifier<'arena>, names: &'a ResolvedNames<'arena>) -> Option<&'arena str> {
    let pos = match id {
        Identifier::Local(l) => l.span.start,
        Identifier::Qualified(q) => q.span.start,
        Identifier::FullyQualified(f) => f.span.start,
    };
    names.resolve(&pos)
}

fn identifier_text<'arena>(id: &Identifier<'arena>) -> &'arena str {
    match id {
        Identifier::Local(l) => l.value,
        Identifier::Qualified(q) => q.value,
        Identifier::FullyQualified(f) => f.value,
    }
}

fn qualify(namespace: &str, local: &str) -> String {
    if namespace.is_empty() {
        local.to_string()
    } else {
        format!("{}\\{}", namespace, local)
    }
}

fn locate(span: Span, ctx: &Ctx<'_, '_>) -> (PathBuf, u32) {
    let line = ctx.file.line_number(span.start.offset) + 1;
    (ctx.abs_path.clone(), line)
}
