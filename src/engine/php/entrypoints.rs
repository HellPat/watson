use std::path::{Path, PathBuf};

use mago_database::file::File;
use mago_names::ResolvedNames;
use mago_span::Span;
use mago_syntax::ast::{
    Argument, ArgumentList, AttributeList, Class, ClassLikeMember, Enum, Expression, Extends,
    Identifier, Implements, Interface, Literal, Method, Namespace, NamespaceBody, Program,
    Property, Statement, Trait,
};

use crate::engine::EntryPoint;

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

const KIND_ROUTE: &str = "symfony.route";
const KIND_COMMAND: &str = "symfony.command";
const KIND_MESSAGE_HANDLER: &str = "symfony.message_handler";
const KIND_CRON_TASK: &str = "symfony.cron_task";
const KIND_PERIODIC_TASK: &str = "symfony.periodic_task";
const KIND_EVENT_LISTENER: &str = "symfony.event_listener";
const KIND_SCHEDULE_PROVIDER: &str = "symfony.schedule_provider";

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
        _ => {}
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
                source: crate::engine::EntryPointSource::Attribute,
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
            source: crate::engine::EntryPointSource::Interface,
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
            source: crate::engine::EntryPointSource::Interface,
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
            source: crate::engine::EntryPointSource::Interface,
            extra: serde_json::Value::Null,
        });
    }

    if interfaces.iter().any(|i| i == SF_SCHEDULE_PROVIDER_INTERFACE_FQN) {
        ctx.out.push(EntryPoint {
            kind: KIND_SCHEDULE_PROVIDER.to_string(),
            name: format!("{}::getSchedule", class_fqn),
            handler_fqn: format!("{}::getSchedule", class_fqn),
            handler_path: path,
            handler_line: line,
            source: crate::engine::EntryPointSource::Interface,
            extra: serde_json::Value::Null,
        });
    }
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
    for member in members.iter() {
        let ClassLikeMember::Property(prop) = member else { continue };
        let Property::Plain(plain) = prop else { continue };
        for item in plain.items.iter() {
            // PropertyItem is either Concrete (= value) or Abstract.
            let mago_syntax::ast::PropertyItem::Concrete(concrete) = item else { continue };
            let var_name = concrete.variable.name;
            if var_name.eq_ignore_ascii_case("$defaultName")
                && let Some(s) = string_literal(concrete.value)
            {
                return Some(s.to_string());
            }
        }
    }
    None
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
                source: crate::engine::EntryPointSource::Attribute,
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
