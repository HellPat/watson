use std::collections::BTreeMap;
use typed_builder::TypedBuilder;
use serde::{Serialize, Deserialize};
/// A physical or virtual address, or a range of addresses, in an 'addressable region' (memory or a
/// binary file).
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "address")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Address {
    /// The address expressed as a byte offset from the start of the addressable region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "absoluteAddress")]
    #[builder(setter(strip_option), default)]
    pub absolute_address: Option<i64>,
    /// A human-readable fully qualified name that is associated with the address.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "fullyQualifiedName")]
    #[builder(setter(strip_option), default)]
    pub fully_qualified_name: Option<String>,
    /// The index within run.addresses of the cached object for this address.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub index: Option<i64>,
    /// An open-ended string that identifies the address kind. 'data', 'function',
    /// 'header','instruction', 'module', 'page', 'section', 'segment', 'stack', 'stackFrame',
    /// 'table' are well-known values.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub kind: Option<String>,
    /// The number of bytes in this range of addresses.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub length: Option<i64>,
    /// A name that is associated with the address, e.g., '.text'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub name: Option<String>,
    /// The byte offset of this address from the absolute or relative address of the parent object.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "offsetFromParent")]
    #[builder(setter(strip_option), default)]
    pub offset_from_parent: Option<i64>,
    /// The index within run.addresses of the parent object.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "parentIndex")]
    #[builder(setter(strip_option), default)]
    pub parent_index: Option<i64>,
    /// Key/value pairs that provide additional information about the address.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The address expressed as a byte offset from the absolute address of the top-most parent
    /// object.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "relativeAddress")]
    #[builder(setter(strip_option), default)]
    pub relative_address: Option<i64>,
}
/// A single artifact. In some cases, this artifact might be nested within another artifact.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "artifact")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Artifact {
    /// The contents of the artifact.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub contents: Option<ArtifactContent>,
    /// A short description of the artifact.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub description: Option<Message>,
    /// Specifies the encoding for an artifact object that refers to a text file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub encoding: Option<String>,
    /// A dictionary, each of whose keys is the name of a hash function and each of whose values is
    /// the hashed value of the artifact produced by the specified hash function.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub hashes: Option<::std::collections::BTreeMap<String, String>>,
    /// The Coordinated Universal Time (UTC) date and time at which the artifact was most recently
    /// modified. See "Date/time properties" in the SARIF spec for the required format.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "lastModifiedTimeUtc")]
    #[builder(setter(strip_option), default)]
    pub last_modified_time_utc: Option<String>,
    /// The length of the artifact in bytes.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub length: Option<i64>,
    /// The location of the artifact.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub location: Option<ArtifactLocation>,
    /// The MIME type (RFC 2045) of the artifact.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "mimeType")]
    #[builder(setter(strip_option), default)]
    pub mime_type: Option<String>,
    /// The offset in bytes of the artifact within its containing artifact.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub offset: Option<i64>,
    /// Identifies the index of the immediate parent of the artifact, if this artifact is nested.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "parentIndex")]
    #[builder(setter(strip_option), default)]
    pub parent_index: Option<i64>,
    /// Key/value pairs that provide additional information about the artifact.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The role or roles played by the artifact in the analysis.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub roles: Option<Vec<serde_json::Value>>,
    /// Specifies the source language for any artifact object that refers to a text file that
    /// contains source code.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "sourceLanguage")]
    #[builder(setter(strip_option), default)]
    pub source_language: Option<String>,
}
/// A change to a single artifact.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "artifactChange")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ArtifactChange {
    /// The location of the artifact to change.
    #[serde(rename = "artifactLocation")]
    pub artifact_location: ArtifactLocation,
    /// Key/value pairs that provide additional information about the change.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// An array of replacement objects, each of which represents the replacement of a single
    /// region in a single artifact specified by 'artifactLocation'.
    #[builder(default)]
    pub replacements: Vec<Replacement>,
}
/// Represents the contents of an artifact.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "artifactContent")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ArtifactContent {
    /// MIME Base64-encoded content from a binary artifact, or from a text artifact in its original
    /// encoding.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub binary: Option<String>,
    /// Key/value pairs that provide additional information about the artifact content.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// An alternate rendered representation of the artifact (e.g., a decompiled representation of
    /// a binary region).
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub rendered: Option<MultiformatMessageString>,
    /// UTF-8-encoded content from a text artifact.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub text: Option<String>,
}
/// Specifies the location of an artifact.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "artifactLocation")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ArtifactLocation {
    /// A short description of the artifact location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub description: Option<Message>,
    /// The index within the run artifacts array of the artifact object associated with the
    /// artifact location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub index: Option<i64>,
    /// Key/value pairs that provide additional information about the artifact location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A string containing a valid relative or absolute URI.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub uri: Option<String>,
    /// A string which indirectly specifies the absolute URI with respect to which a relative URI
    /// in the "uri" property is interpreted.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "uriBaseId")]
    #[builder(setter(strip_option), default)]
    pub uri_base_id: Option<String>,
}
/// An artifact relevant to a result.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "attachment")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Attachment {
    /// The location of the attachment.
    #[serde(rename = "artifactLocation")]
    pub artifact_location: ArtifactLocation,
    /// A message describing the role played by the attachment.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub description: Option<Message>,
    /// Key/value pairs that provide additional information about the attachment.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// An array of rectangles specifying areas of interest within the image.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub rectangles: Option<Vec<Rectangle>>,
    /// An array of regions of interest within the attachment.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub regions: Option<Vec<Region>>,
}
/// A set of threadFlows which together describe a pattern of code execution relevant to detecting
/// a result.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "codeFlow")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct CodeFlow {
    /// A message relevant to the code flow.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub message: Option<Message>,
    /// Key/value pairs that provide additional information about the code flow.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// An array of one or more unique threadFlow objects, each of which describes the progress of
    /// a program through a thread of execution.
    #[serde(rename = "threadFlows")]
    #[builder(default)]
    pub thread_flows: Vec<ThreadFlow>,
}
/// Information about how a specific rule or notification was reconfigured at runtime.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "configurationOverride")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ConfigurationOverride {
    /// Specifies how the rule or notification was configured during the scan.
    pub configuration: ReportingConfiguration,
    /// A reference used to locate the descriptor whose configuration was overridden.
    pub descriptor: ReportingDescriptorReference,
    /// Key/value pairs that provide additional information about the configuration override.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// Describes how a converter transformed the output of a static analysis tool from the analysis
/// tool's native output format into the SARIF format.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "conversion")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Conversion {
    /// The locations of the analysis tool's per-run log files.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "analysisToolLogFiles")]
    #[builder(setter(strip_option), default)]
    pub analysis_tool_log_files: Option<Vec<ArtifactLocation>>,
    /// An invocation object that describes the invocation of the converter.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub invocation: Option<Invocation>,
    /// Key/value pairs that provide additional information about the conversion.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A tool object that describes the converter.
    pub tool: Tool,
}
/// Represents a directed edge in a graph.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "edge")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Edge {
    /// A string that uniquely identifies the edge within its graph.
    pub id: String,
    /// A short description of the edge.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub label: Option<Message>,
    /// Key/value pairs that provide additional information about the edge.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// Identifies the source node (the node at which the edge starts).
    #[serde(rename = "sourceNodeId")]
    pub source_node_id: String,
    /// Identifies the target node (the node at which the edge ends).
    #[serde(rename = "targetNodeId")]
    pub target_node_id: String,
}
/// Represents the traversal of a single edge during a graph traversal.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "edgeTraversal")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct EdgeTraversal {
    /// Identifies the edge being traversed.
    #[serde(rename = "edgeId")]
    pub edge_id: String,
    /// The values of relevant expressions after the edge has been traversed.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "finalState")]
    #[builder(setter(strip_option), default)]
    pub final_state: Option<
        ::std::collections::BTreeMap<String, MultiformatMessageString>,
    >,
    /// A message to display to the user as the edge is traversed.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub message: Option<Message>,
    /// Key/value pairs that provide additional information about the edge traversal.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The number of edge traversals necessary to return from a nested graph.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "stepOverEdgeCount")]
    #[builder(setter(strip_option), default)]
    pub step_over_edge_count: Option<i64>,
}
/// Describes a runtime exception encountered during the execution of an analysis tool.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "exception")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Exception {
    /// An array of exception objects each of which is considered a cause of this exception.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "innerExceptions")]
    #[builder(setter(strip_option), default)]
    pub inner_exceptions: Option<Vec<Exception>>,
    /// A string that identifies the kind of exception, for example, the fully qualified type name
    /// of an object that was thrown, or the symbolic name of a signal.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub kind: Option<String>,
    /// A message that describes the exception.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub message: Option<String>,
    /// Key/value pairs that provide additional information about the exception.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The sequence of function calls leading to the exception.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub stack: Option<Stack>,
}
/// The top-level element of an external property file.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "externalProperties")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ExternalProperties {
    /// Addresses that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub addresses: Option<Vec<Address>>,
    /// An array of artifact objects that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub artifacts: Option<Vec<Artifact>>,
    /// A conversion object that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub conversion: Option<Conversion>,
    /// The analysis tool object that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub driver: Option<ToolComponent>,
    /// Tool extensions that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub extensions: Option<Vec<ToolComponent>>,
    /// Key/value pairs that provide additional information that will be merged with a separate
    /// run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "externalizedProperties")]
    #[builder(setter(strip_option), default)]
    pub externalized_properties: Option<PropertyBag>,
    /// An array of graph objects that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub graphs: Option<Vec<Graph>>,
    /// A stable, unique identifier for this external properties object, in the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub guid: Option<String>,
    /// Describes the invocation of the analysis tool that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub invocations: Option<Vec<Invocation>>,
    /// An array of logical locations such as namespaces, types or functions that will be merged
    /// with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "logicalLocations")]
    #[builder(setter(strip_option), default)]
    pub logical_locations: Option<Vec<LogicalLocation>>,
    /// Tool policies that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub policies: Option<Vec<ToolComponent>>,
    /// Key/value pairs that provide additional information about the external properties.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// An array of result objects that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub results: Option<Vec<Result>>,
    /// A stable, unique identifier for the run associated with this external properties object, in
    /// the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "runGuid")]
    #[builder(setter(strip_option), default)]
    pub run_guid: Option<String>,
    /// The URI of the JSON schema corresponding to the version of the external property file
    /// format.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub schema: Option<String>,
    /// Tool taxonomies that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub taxonomies: Option<Vec<ToolComponent>>,
    /// An array of threadFlowLocation objects that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "threadFlowLocations")]
    #[builder(setter(strip_option), default)]
    pub thread_flow_locations: Option<Vec<ThreadFlowLocation>>,
    /// Tool translations that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub translations: Option<Vec<ToolComponent>>,
    /// The SARIF format version of this external properties object.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub version: Option<serde_json::Value>,
    /// Requests that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webRequests")]
    #[builder(setter(strip_option), default)]
    pub web_requests: Option<Vec<WebRequest>>,
    /// Responses that will be merged with a separate run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webResponses")]
    #[builder(setter(strip_option), default)]
    pub web_responses: Option<Vec<WebResponse>>,
}
/// Contains information that enables a SARIF consumer to locate the external property file that
/// contains the value of an externalized property associated with the run.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "externalPropertyFileReference")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ExternalPropertyFileReference {
    /// A stable, unique identifier for the external property file in the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub guid: Option<String>,
    /// A non-negative integer specifying the number of items contained in the external property
    /// file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "itemCount")]
    #[builder(setter(strip_option), default)]
    pub item_count: Option<i64>,
    /// The location of the external property file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub location: Option<ArtifactLocation>,
    /// Key/value pairs that provide additional information about the external property file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// References to external property files that should be inlined with the content of a root log
/// file.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "externalPropertyFileReferences")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ExternalPropertyFileReferences {
    /// An array of external property files containing run.addresses arrays to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub addresses: Option<Vec<ExternalPropertyFileReference>>,
    /// An array of external property files containing run.artifacts arrays to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub artifacts: Option<Vec<ExternalPropertyFileReference>>,
    /// An external property file containing a run.conversion object to be merged with the root log
    /// file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub conversion: Option<ExternalPropertyFileReference>,
    /// An external property file containing a run.driver object to be merged with the root log
    /// file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub driver: Option<ExternalPropertyFileReference>,
    /// An array of external property files containing run.extensions arrays to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub extensions: Option<Vec<ExternalPropertyFileReference>>,
    /// An external property file containing a run.properties object to be merged with the root log
    /// file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "externalizedProperties")]
    #[builder(setter(strip_option), default)]
    pub externalized_properties: Option<ExternalPropertyFileReference>,
    /// An array of external property files containing a run.graphs object to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub graphs: Option<Vec<ExternalPropertyFileReference>>,
    /// An array of external property files containing run.invocations arrays to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub invocations: Option<Vec<ExternalPropertyFileReference>>,
    /// An array of external property files containing run.logicalLocations arrays to be merged
    /// with the root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "logicalLocations")]
    #[builder(setter(strip_option), default)]
    pub logical_locations: Option<Vec<ExternalPropertyFileReference>>,
    /// An array of external property files containing run.policies arrays to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub policies: Option<Vec<ExternalPropertyFileReference>>,
    /// Key/value pairs that provide additional information about the external property files.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// An array of external property files containing run.results arrays to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub results: Option<Vec<ExternalPropertyFileReference>>,
    /// An array of external property files containing run.taxonomies arrays to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub taxonomies: Option<Vec<ExternalPropertyFileReference>>,
    /// An array of external property files containing run.threadFlowLocations arrays to be merged
    /// with the root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "threadFlowLocations")]
    #[builder(setter(strip_option), default)]
    pub thread_flow_locations: Option<Vec<ExternalPropertyFileReference>>,
    /// An array of external property files containing run.translations arrays to be merged with
    /// the root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub translations: Option<Vec<ExternalPropertyFileReference>>,
    /// An array of external property files containing run.requests arrays to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webRequests")]
    #[builder(setter(strip_option), default)]
    pub web_requests: Option<Vec<ExternalPropertyFileReference>>,
    /// An array of external property files containing run.responses arrays to be merged with the
    /// root log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webResponses")]
    #[builder(setter(strip_option), default)]
    pub web_responses: Option<Vec<ExternalPropertyFileReference>>,
}
/// A proposed fix for the problem represented by a result object. A fix specifies a set of
/// artifacts to modify. For each artifact, it specifies a set of bytes to remove, and provides a
/// set of new bytes to replace them.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "fix")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Fix {
    /// One or more artifact changes that comprise a fix for a result.
    #[serde(rename = "artifactChanges")]
    #[builder(default)]
    pub artifact_changes: Vec<ArtifactChange>,
    /// A message that describes the proposed fix, enabling viewers to present the proposed change
    /// to an end user.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub description: Option<Message>,
    /// Key/value pairs that provide additional information about the fix.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// A network of nodes and directed edges that describes some aspect of the structure of the code
/// (for example, a call graph).
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "graph")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Graph {
    /// A description of the graph.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub description: Option<Message>,
    /// An array of edge objects representing the edges of the graph.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub edges: Option<Vec<Edge>>,
    /// An array of node objects representing the nodes of the graph.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub nodes: Option<Vec<Node>>,
    /// Key/value pairs that provide additional information about the graph.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// Represents a path through a graph.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "graphTraversal")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct GraphTraversal {
    /// A description of this graph traversal.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub description: Option<Message>,
    /// The sequences of edges traversed by this graph traversal.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "edgeTraversals")]
    #[builder(setter(strip_option), default)]
    pub edge_traversals: Option<Vec<EdgeTraversal>>,
    /// Values of relevant expressions at the start of the graph traversal that remain constant for
    /// the graph traversal.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "immutableState")]
    #[builder(setter(strip_option), default)]
    pub immutable_state: Option<
        ::std::collections::BTreeMap<String, MultiformatMessageString>,
    >,
    /// Values of relevant expressions at the start of the graph traversal that may change during
    /// graph traversal.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "initialState")]
    #[builder(setter(strip_option), default)]
    pub initial_state: Option<
        ::std::collections::BTreeMap<String, MultiformatMessageString>,
    >,
    /// Key/value pairs that provide additional information about the graph traversal.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The index within the result.graphs to be associated with the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "resultGraphIndex")]
    #[builder(setter(strip_option), default)]
    pub result_graph_index: Option<i64>,
    /// The index within the run.graphs to be associated with the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "runGraphIndex")]
    #[builder(setter(strip_option), default)]
    pub run_graph_index: Option<i64>,
}
/// The runtime environment of the analysis tool run.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "invocation")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Invocation {
    /// The account under which the invocation occurred.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub account: Option<String>,
    /// An array of strings, containing in order the command line arguments passed to the tool from
    /// the operating system.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub arguments: Option<Vec<String>>,
    /// The command line used to invoke the tool.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "commandLine")]
    #[builder(setter(strip_option), default)]
    pub command_line: Option<String>,
    /// The Coordinated Universal Time (UTC) date and time at which the invocation ended. See
    /// "Date/time properties" in the SARIF spec for the required format.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "endTimeUtc")]
    #[builder(setter(strip_option), default)]
    pub end_time_utc: Option<String>,
    /// The environment variables associated with the analysis tool process, expressed as key/value
    /// pairs.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "environmentVariables")]
    #[builder(setter(strip_option), default)]
    pub environment_variables: Option<::std::collections::BTreeMap<String, String>>,
    /// An absolute URI specifying the location of the executable that was invoked.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "executableLocation")]
    #[builder(setter(strip_option), default)]
    pub executable_location: Option<ArtifactLocation>,
    /// Specifies whether the tool's execution completed successfully.
    #[serde(rename = "executionSuccessful")]
    pub execution_successful: bool,
    /// The process exit code.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "exitCode")]
    #[builder(setter(strip_option), default)]
    pub exit_code: Option<i64>,
    /// The reason for the process exit.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "exitCodeDescription")]
    #[builder(setter(strip_option), default)]
    pub exit_code_description: Option<String>,
    /// The name of the signal that caused the process to exit.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "exitSignalName")]
    #[builder(setter(strip_option), default)]
    pub exit_signal_name: Option<String>,
    /// The numeric value of the signal that caused the process to exit.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "exitSignalNumber")]
    #[builder(setter(strip_option), default)]
    pub exit_signal_number: Option<i64>,
    /// The machine on which the invocation occurred.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub machine: Option<String>,
    /// An array of configurationOverride objects that describe notifications related runtime
    /// overrides.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "notificationConfigurationOverrides")]
    #[builder(setter(strip_option), default)]
    pub notification_configuration_overrides: Option<Vec<ConfigurationOverride>>,
    /// The id of the process in which the invocation occurred.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "processId")]
    #[builder(setter(strip_option), default)]
    pub process_id: Option<i64>,
    /// The reason given by the operating system that the process failed to start.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "processStartFailureMessage")]
    #[builder(setter(strip_option), default)]
    pub process_start_failure_message: Option<String>,
    /// Key/value pairs that provide additional information about the invocation.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The locations of any response files specified on the tool's command line.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "responseFiles")]
    #[builder(setter(strip_option), default)]
    pub response_files: Option<Vec<ArtifactLocation>>,
    /// An array of configurationOverride objects that describe rules related runtime overrides.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "ruleConfigurationOverrides")]
    #[builder(setter(strip_option), default)]
    pub rule_configuration_overrides: Option<Vec<ConfigurationOverride>>,
    /// The Coordinated Universal Time (UTC) date and time at which the invocation started. See
    /// "Date/time properties" in the SARIF spec for the required format.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "startTimeUtc")]
    #[builder(setter(strip_option), default)]
    pub start_time_utc: Option<String>,
    /// A file containing the standard error stream from the process that was invoked.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub stderr: Option<ArtifactLocation>,
    /// A file containing the standard input stream to the process that was invoked.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub stdin: Option<ArtifactLocation>,
    /// A file containing the standard output stream from the process that was invoked.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub stdout: Option<ArtifactLocation>,
    /// A file containing the interleaved standard output and standard error stream from the
    /// process that was invoked.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "stdoutStderr")]
    #[builder(setter(strip_option), default)]
    pub stdout_stderr: Option<ArtifactLocation>,
    /// A list of conditions detected by the tool that are relevant to the tool's configuration.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "toolConfigurationNotifications")]
    #[builder(setter(strip_option), default)]
    pub tool_configuration_notifications: Option<Vec<Notification>>,
    /// A list of runtime conditions detected by the tool during the analysis.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "toolExecutionNotifications")]
    #[builder(setter(strip_option), default)]
    pub tool_execution_notifications: Option<Vec<Notification>>,
    /// The working directory for the invocation.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "workingDirectory")]
    #[builder(setter(strip_option), default)]
    pub working_directory: Option<ArtifactLocation>,
}
/// A location within a programming artifact.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "location")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Location {
    /// A set of regions relevant to the location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub annotations: Option<Vec<Region>>,
    /// Value that distinguishes this location from all other locations within a single result
    /// object.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub id: Option<i64>,
    /// The logical locations associated with the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "logicalLocations")]
    #[builder(setter(strip_option), default)]
    pub logical_locations: Option<Vec<LogicalLocation>>,
    /// A message relevant to the location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub message: Option<Message>,
    /// Identifies the artifact and region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "physicalLocation")]
    #[builder(setter(strip_option), default)]
    pub physical_location: Option<PhysicalLocation>,
    /// Key/value pairs that provide additional information about the location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// An array of objects that describe relationships between this location and others.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub relationships: Option<Vec<LocationRelationship>>,
}
/// Information about the relation of one location to another.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "locationRelationship")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct LocationRelationship {
    /// A description of the location relationship.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub description: Option<Message>,
    /// A set of distinct strings that categorize the relationship. Well-known kinds include
    /// 'includes', 'isIncludedBy' and 'relevant'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub kinds: Option<Vec<String>>,
    /// Key/value pairs that provide additional information about the location relationship.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A reference to the related location.
    pub target: i64,
}
/// A logical location of a construct that produced a result.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "logicalLocation")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct LogicalLocation {
    /// The machine-readable name for the logical location, such as a mangled function name
    /// provided by a C++ compiler that encodes calling convention, return type and other details
    /// along with the function name.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "decoratedName")]
    #[builder(setter(strip_option), default)]
    pub decorated_name: Option<String>,
    /// The human-readable fully qualified name of the logical location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "fullyQualifiedName")]
    #[builder(setter(strip_option), default)]
    pub fully_qualified_name: Option<String>,
    /// The index within the logical locations array.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub index: Option<i64>,
    /// The type of construct this logical location component refers to. Should be one of
    /// 'function', 'member', 'module', 'namespace', 'parameter', 'resource', 'returnType', 'type',
    /// 'variable', 'object', 'array', 'property', 'value', 'element', 'text', 'attribute',
    /// 'comment', 'declaration', 'dtd' or 'processingInstruction', if any of those accurately
    /// describe the construct.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub kind: Option<String>,
    /// Identifies the construct in which the result occurred. For example, this property might
    /// contain the name of a class or a method.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub name: Option<String>,
    /// Identifies the index of the immediate parent of the construct in which the result was
    /// detected. For example, this property might point to a logical location that represents the
    /// namespace that holds a type.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "parentIndex")]
    #[builder(setter(strip_option), default)]
    pub parent_index: Option<i64>,
    /// Key/value pairs that provide additional information about the logical location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// Encapsulates a message intended to be read by the end user.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "message")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Message {
    /// An array of strings to substitute into the message string.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub arguments: Option<Vec<String>>,
    /// The identifier for this message.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub id: Option<String>,
    /// A Markdown message string.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub markdown: Option<String>,
    /// Key/value pairs that provide additional information about the message.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A plain text message string.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub text: Option<String>,
}
/// A message string or message format string rendered in multiple formats.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "multiformatMessageString")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct MultiformatMessageString {
    /// A Markdown message string or format string.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub markdown: Option<String>,
    /// Key/value pairs that provide additional information about the message.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A plain text message string or format string.
    pub text: String,
}
/// Represents a node in a graph.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "node")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Node {
    /// Array of child nodes.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub children: Option<Vec<Node>>,
    /// A string that uniquely identifies the node within its graph.
    pub id: String,
    /// A short description of the node.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub label: Option<Message>,
    /// A code location associated with the node.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub location: Option<Location>,
    /// Key/value pairs that provide additional information about the node.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// Describes a condition relevant to the tool itself, as opposed to being relevant to a target
/// being analyzed by the tool.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "notification")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Notification {
    /// A reference used to locate the rule descriptor associated with this notification.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "associatedRule")]
    #[builder(setter(strip_option), default)]
    pub associated_rule: Option<ReportingDescriptorReference>,
    /// A reference used to locate the descriptor relevant to this notification.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub descriptor: Option<ReportingDescriptorReference>,
    /// The runtime exception, if any, relevant to this notification.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub exception: Option<Exception>,
    /// A value specifying the severity level of the notification.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub level: Option<serde_json::Value>,
    /// The locations relevant to this notification.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub locations: Option<Vec<Location>>,
    /// A message that describes the condition that was encountered.
    pub message: Message,
    /// Key/value pairs that provide additional information about the notification.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The thread identifier of the code that generated the notification.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "threadId")]
    #[builder(setter(strip_option), default)]
    pub thread_id: Option<i64>,
    /// The Coordinated Universal Time (UTC) date and time at which the analysis tool generated the
    /// notification.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "timeUtc")]
    #[builder(setter(strip_option), default)]
    pub time_utc: Option<String>,
}
/// A physical location relevant to a result. Specifies a reference to a programming artifact
/// together with a range of bytes or characters within that artifact.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "physicalLocation")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct PhysicalLocation {
    /// The address of the location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub address: Option<Address>,
    /// The location of the artifact.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "artifactLocation")]
    #[builder(setter(strip_option), default)]
    pub artifact_location: Option<ArtifactLocation>,
    /// Specifies a portion of the artifact that encloses the region. Allows a viewer to display
    /// additional context around the region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "contextRegion")]
    #[builder(setter(strip_option), default)]
    pub context_region: Option<Region>,
    /// Key/value pairs that provide additional information about the physical location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// Specifies a portion of the artifact.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub region: Option<Region>,
}
/// Key/value pairs that provide additional information about the object.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "propertyBag")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct PropertyBag {
    /// A set of distinct strings that provide additional information.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub tags: Option<Vec<String>>,
    ///Arbitrary properties to include in the PropertyBag
    #[serde(flatten)]
    #[builder(default = ::std::collections::BTreeMap::new())]
    pub additional_properties: BTreeMap<String, serde_json::Value>,
}
/// An area within an image.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "rectangle")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Rectangle {
    /// The Y coordinate of the bottom edge of the rectangle, measured in the image's natural
    /// units.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub bottom: Option<f64>,
    /// The X coordinate of the left edge of the rectangle, measured in the image's natural units.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub left: Option<f64>,
    /// A message relevant to the rectangle.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub message: Option<Message>,
    /// Key/value pairs that provide additional information about the rectangle.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The X coordinate of the right edge of the rectangle, measured in the image's natural units.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub right: Option<f64>,
    /// The Y coordinate of the top edge of the rectangle, measured in the image's natural units.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub top: Option<f64>,
}
/// A region within an artifact where a result was detected.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "region")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Region {
    /// The length of the region in bytes.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "byteLength")]
    #[builder(setter(strip_option), default)]
    pub byte_length: Option<i64>,
    /// The zero-based offset from the beginning of the artifact of the first byte in the region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "byteOffset")]
    #[builder(setter(strip_option), default)]
    pub byte_offset: Option<i64>,
    /// The length of the region in characters.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "charLength")]
    #[builder(setter(strip_option), default)]
    pub char_length: Option<i64>,
    /// The zero-based offset from the beginning of the artifact of the first character in the
    /// region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "charOffset")]
    #[builder(setter(strip_option), default)]
    pub char_offset: Option<i64>,
    /// The column number of the character following the end of the region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "endColumn")]
    #[builder(setter(strip_option), default)]
    pub end_column: Option<i64>,
    /// The line number of the last character in the region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "endLine")]
    #[builder(setter(strip_option), default)]
    pub end_line: Option<i64>,
    /// A message relevant to the region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub message: Option<Message>,
    /// Key/value pairs that provide additional information about the region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The portion of the artifact contents within the specified region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub snippet: Option<ArtifactContent>,
    /// Specifies the source language, if any, of the portion of the artifact specified by the
    /// region object.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "sourceLanguage")]
    #[builder(setter(strip_option), default)]
    pub source_language: Option<String>,
    /// The column number of the first character in the region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "startColumn")]
    #[builder(setter(strip_option), default)]
    pub start_column: Option<i64>,
    /// The line number of the first character in the region.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "startLine")]
    #[builder(setter(strip_option), default)]
    pub start_line: Option<i64>,
}
/// The replacement of a single region of an artifact.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "replacement")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Replacement {
    /// The region of the artifact to delete.
    #[serde(rename = "deletedRegion")]
    pub deleted_region: Region,
    /// The content to insert at the location specified by the 'deletedRegion' property.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "insertedContent")]
    #[builder(setter(strip_option), default)]
    pub inserted_content: Option<ArtifactContent>,
    /// Key/value pairs that provide additional information about the replacement.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// Information about a rule or notification that can be configured at runtime.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "reportingConfiguration")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ReportingConfiguration {
    /// Specifies whether the report may be produced during the scan.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub enabled: Option<bool>,
    /// Specifies the failure level for the report.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub level: Option<serde_json::Value>,
    /// Contains configuration information specific to a report.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub parameters: Option<PropertyBag>,
    /// Key/value pairs that provide additional information about the reporting configuration.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// Specifies the relative priority of the report. Used for analysis output only.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub rank: Option<f64>,
}
/// Metadata that describes a specific report produced by the tool, as part of the analysis it
/// provides or its runtime reporting.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "reportingDescriptor")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ReportingDescriptor {
    /// Default reporting configuration information.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "defaultConfiguration")]
    #[builder(setter(strip_option), default)]
    pub default_configuration: Option<ReportingConfiguration>,
    /// An array of unique identifies in the form of a GUID by which this report was known in some
    /// previous version of the analysis tool.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "deprecatedGuids")]
    #[builder(setter(strip_option), default)]
    pub deprecated_guids: Option<Vec<String>>,
    /// An array of stable, opaque identifiers by which this report was known in some previous
    /// version of the analysis tool.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "deprecatedIds")]
    #[builder(setter(strip_option), default)]
    pub deprecated_ids: Option<Vec<String>>,
    /// An array of readable identifiers by which this report was known in some previous version of
    /// the analysis tool.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "deprecatedNames")]
    #[builder(setter(strip_option), default)]
    pub deprecated_names: Option<Vec<String>>,
    /// A description of the report. Should, as far as possible, provide details sufficient to
    /// enable resolution of any problem indicated by the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "fullDescription")]
    #[builder(setter(strip_option), default)]
    pub full_description: Option<MultiformatMessageString>,
    /// A unique identifier for the reporting descriptor in the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub guid: Option<String>,
    /// Provides the primary documentation for the report, useful when there is no online
    /// documentation.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub help: Option<MultiformatMessageString>,
    /// A URI where the primary documentation for the report can be found.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "helpUri")]
    #[builder(setter(strip_option), default)]
    pub help_uri: Option<String>,
    /// A stable, opaque identifier for the report.
    pub id: String,
    /// A set of name/value pairs with arbitrary names. Each value is a multiformatMessageString
    /// object, which holds message strings in plain text and (optionally) Markdown format. The
    /// strings can include placeholders, which can be used to construct a message in combination
    /// with an arbitrary number of additional string arguments.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "messageStrings")]
    #[builder(setter(strip_option), default)]
    pub message_strings: Option<
        ::std::collections::BTreeMap<String, MultiformatMessageString>,
    >,
    /// A report identifier that is understandable to an end user.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub name: Option<String>,
    /// Key/value pairs that provide additional information about the report.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// An array of objects that describe relationships between this reporting descriptor and
    /// others.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub relationships: Option<Vec<ReportingDescriptorRelationship>>,
    /// A concise description of the report. Should be a single sentence that is understandable
    /// when visible space is limited to a single line of text.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "shortDescription")]
    #[builder(setter(strip_option), default)]
    pub short_description: Option<MultiformatMessageString>,
}
/// Information about how to locate a relevant reporting descriptor.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "reportingDescriptorReference")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ReportingDescriptorReference {
    /// A guid that uniquely identifies the descriptor.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub guid: Option<String>,
    /// The id of the descriptor.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub id: Option<String>,
    /// The index into an array of descriptors in toolComponent.ruleDescriptors,
    /// toolComponent.notificationDescriptors, or toolComponent.taxonomyDescriptors, depending on
    /// context.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub index: Option<i64>,
    /// Key/value pairs that provide additional information about the reporting descriptor
    /// reference.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A reference used to locate the toolComponent associated with the descriptor.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "toolComponent")]
    #[builder(setter(strip_option), default)]
    pub tool_component: Option<ToolComponentReference>,
}
/// Information about the relation of one reporting descriptor to another.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "reportingDescriptorRelationship")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ReportingDescriptorRelationship {
    /// A description of the reporting descriptor relationship.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub description: Option<Message>,
    /// A set of distinct strings that categorize the relationship. Well-known kinds include
    /// 'canPrecede', 'canFollow', 'willPrecede', 'willFollow', 'superset', 'subset', 'equal',
    /// 'disjoint', 'relevant', and 'incomparable'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub kinds: Option<Vec<String>>,
    /// Key/value pairs that provide additional information about the reporting descriptor
    /// reference.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A reference to the related reporting descriptor.
    pub target: ReportingDescriptorReference,
}
/// A result produced by an analysis tool.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "result")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Result {
    /// Identifies the artifact that the analysis tool was instructed to scan. This need not be the
    /// same as the artifact where the result actually occurred.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "analysisTarget")]
    #[builder(setter(strip_option), default)]
    pub analysis_target: Option<ArtifactLocation>,
    /// A set of artifacts relevant to the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub attachments: Option<Vec<Attachment>>,
    /// The state of a result relative to a baseline of a previous run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "baselineState")]
    #[builder(setter(strip_option), default)]
    pub baseline_state: Option<serde_json::Value>,
    /// An array of 'codeFlow' objects relevant to the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "codeFlows")]
    #[builder(setter(strip_option), default)]
    pub code_flows: Option<Vec<CodeFlow>>,
    /// A stable, unique identifier for the equivalence class of logically identical results to
    /// which this result belongs, in the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "correlationGuid")]
    #[builder(setter(strip_option), default)]
    pub correlation_guid: Option<String>,
    /// A set of strings each of which individually defines a stable, unique identity for the
    /// result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub fingerprints: Option<::std::collections::BTreeMap<String, String>>,
    /// An array of 'fix' objects, each of which represents a proposed fix to the problem indicated
    /// by the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub fixes: Option<Vec<Fix>>,
    /// An array of one or more unique 'graphTraversal' objects.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "graphTraversals")]
    #[builder(setter(strip_option), default)]
    pub graph_traversals: Option<Vec<GraphTraversal>>,
    /// An array of zero or more unique graph objects associated with the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub graphs: Option<Vec<Graph>>,
    /// A stable, unique identifier for the result in the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub guid: Option<String>,
    /// An absolute URI at which the result can be viewed.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "hostedViewerUri")]
    #[builder(setter(strip_option), default)]
    pub hosted_viewer_uri: Option<String>,
    /// A value that categorizes results by evaluation state.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub kind: Option<ResultKind>,
    /// A value specifying the severity level of the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub level: Option<ResultLevel>,
    /// The set of locations where the result was detected. Specify only one location unless the
    /// problem indicated by the result can only be corrected by making a change at every specified
    /// location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub locations: Option<Vec<Location>>,
    /// A message that describes the result. The first sentence of the message only will be
    /// displayed when visible space is limited.
    pub message: Message,
    /// A positive integer specifying the number of times this logically unique result was observed
    /// in this run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "occurrenceCount")]
    #[builder(setter(strip_option), default)]
    pub occurrence_count: Option<i64>,
    /// A set of strings that contribute to the stable, unique identity of the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "partialFingerprints")]
    #[builder(setter(strip_option), default)]
    pub partial_fingerprints: Option<::std::collections::BTreeMap<String, String>>,
    /// Key/value pairs that provide additional information about the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// Information about how and when the result was detected.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub provenance: Option<ResultProvenance>,
    /// A number representing the priority or importance of the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub rank: Option<f64>,
    /// A set of locations relevant to this result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "relatedLocations")]
    #[builder(setter(strip_option), default)]
    pub related_locations: Option<Vec<Location>>,
    /// A reference used to locate the rule descriptor relevant to this result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub rule: Option<ReportingDescriptorReference>,
    /// The stable, unique identifier of the rule, if any, to which this result is relevant.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "ruleId")]
    #[builder(setter(strip_option), default)]
    pub rule_id: Option<String>,
    /// The index within the tool component rules array of the rule object associated with this
    /// result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "ruleIndex")]
    #[builder(setter(strip_option), default)]
    pub rule_index: Option<i64>,
    /// An array of 'stack' objects relevant to the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub stacks: Option<Vec<Stack>>,
    /// A set of suppressions relevant to this result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub suppressions: Option<Vec<Suppression>>,
    /// An array of references to taxonomy reporting descriptors that are applicable to the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub taxa: Option<Vec<ReportingDescriptorReference>>,
    /// A web request associated with this result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webRequest")]
    #[builder(setter(strip_option), default)]
    pub web_request: Option<WebRequest>,
    /// A web response associated with this result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webResponse")]
    #[builder(setter(strip_option), default)]
    pub web_response: Option<WebResponse>,
    /// The URIs of the work items associated with this result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "workItemUris")]
    #[builder(setter(strip_option), default)]
    pub work_item_uris: Option<Vec<String>>,
}
/// Contains information about how and when a result was detected.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "resultProvenance")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ResultProvenance {
    /// An array of physicalLocation objects which specify the portions of an analysis tool's
    /// output that a converter transformed into the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "conversionSources")]
    #[builder(setter(strip_option), default)]
    pub conversion_sources: Option<Vec<PhysicalLocation>>,
    /// A GUID-valued string equal to the automationDetails.guid property of the run in which the
    /// result was first detected.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "firstDetectionRunGuid")]
    #[builder(setter(strip_option), default)]
    pub first_detection_run_guid: Option<String>,
    /// The Coordinated Universal Time (UTC) date and time at which the result was first detected.
    /// See "Date/time properties" in the SARIF spec for the required format.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "firstDetectionTimeUtc")]
    #[builder(setter(strip_option), default)]
    pub first_detection_time_utc: Option<String>,
    /// The index within the run.invocations array of the invocation object which describes the
    /// tool invocation that detected the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "invocationIndex")]
    #[builder(setter(strip_option), default)]
    pub invocation_index: Option<i64>,
    /// A GUID-valued string equal to the automationDetails.guid property of the run in which the
    /// result was most recently detected.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "lastDetectionRunGuid")]
    #[builder(setter(strip_option), default)]
    pub last_detection_run_guid: Option<String>,
    /// The Coordinated Universal Time (UTC) date and time at which the result was most recently
    /// detected. See "Date/time properties" in the SARIF spec for the required format.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "lastDetectionTimeUtc")]
    #[builder(setter(strip_option), default)]
    pub last_detection_time_utc: Option<String>,
    /// Key/value pairs that provide additional information about the result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// Describes a single run of an analysis tool, and contains the reported output of that run.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "run")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Run {
    /// Addresses associated with this run instance, if any.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub addresses: Option<Vec<Address>>,
    /// An array of artifact objects relevant to the run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub artifacts: Option<Vec<Artifact>>,
    /// Automation details that describe this run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "automationDetails")]
    #[builder(setter(strip_option), default)]
    pub automation_details: Option<RunAutomationDetails>,
    /// The 'guid' property of a previous SARIF 'run' that comprises the baseline that was used to
    /// compute result 'baselineState' properties for the run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "baselineGuid")]
    #[builder(setter(strip_option), default)]
    pub baseline_guid: Option<String>,
    /// Specifies the unit in which the tool measures columns.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "columnKind")]
    #[builder(setter(strip_option), default)]
    pub column_kind: Option<serde_json::Value>,
    /// A conversion object that describes how a converter transformed an analysis tool's native
    /// reporting format into the SARIF format.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub conversion: Option<Conversion>,
    /// Specifies the default encoding for any artifact object that refers to a text file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "defaultEncoding")]
    #[builder(setter(strip_option), default)]
    pub default_encoding: Option<String>,
    /// Specifies the default source language for any artifact object that refers to a text file
    /// that contains source code.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "defaultSourceLanguage")]
    #[builder(setter(strip_option), default)]
    pub default_source_language: Option<String>,
    /// References to external property files that should be inlined with the content of a root log
    /// file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "externalPropertyFileReferences")]
    #[builder(setter(strip_option), default)]
    pub external_property_file_references: Option<ExternalPropertyFileReferences>,
    /// An array of zero or more unique graph objects associated with the run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub graphs: Option<Vec<Graph>>,
    /// Describes the invocation of the analysis tool.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub invocations: Option<Vec<Invocation>>,
    /// The language of the messages emitted into the log file during this run (expressed as an ISO
    /// 639-1 two-letter lowercase culture code) and an optional region (expressed as an ISO 3166-1
    /// two-letter uppercase subculture code associated with a country or region). The casing is
    /// recommended but not required (in order for this data to conform to RFC5646).
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub language: Option<String>,
    /// An array of logical locations such as namespaces, types or functions.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "logicalLocations")]
    #[builder(setter(strip_option), default)]
    pub logical_locations: Option<Vec<LogicalLocation>>,
    /// An ordered list of character sequences that were treated as line breaks when computing
    /// region information for the run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "newlineSequences")]
    #[builder(setter(strip_option), default)]
    pub newline_sequences: Option<Vec<String>>,
    /// The artifact location specified by each uriBaseId symbol on the machine where the tool
    /// originally ran.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "originalUriBaseIds")]
    #[builder(setter(strip_option), default)]
    pub original_uri_base_ids: Option<
        ::std::collections::BTreeMap<String, ArtifactLocation>,
    >,
    /// Contains configurations that may potentially override both
    /// reportingDescriptor.defaultConfiguration (the tool's default severities) and
    /// invocation.configurationOverrides (severities established at run-time from the command
    /// line).
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub policies: Option<Vec<ToolComponent>>,
    /// Key/value pairs that provide additional information about the run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// An array of strings used to replace sensitive information in a redaction-aware property.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "redactionTokens")]
    #[builder(setter(strip_option), default)]
    pub redaction_tokens: Option<Vec<String>>,
    /// The set of results contained in an SARIF log. The results array can be omitted when a run
    /// is solely exporting rules metadata. It must be present (but may be empty) if a log file
    /// represents an actual scan.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub results: Option<Vec<Result>>,
    /// Automation details that describe the aggregate of runs to which this run belongs.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "runAggregates")]
    #[builder(setter(strip_option), default)]
    pub run_aggregates: Option<Vec<RunAutomationDetails>>,
    /// A specialLocations object that defines locations of special significance to SARIF
    /// consumers.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "specialLocations")]
    #[builder(setter(strip_option), default)]
    pub special_locations: Option<SpecialLocations>,
    /// An array of toolComponent objects relevant to a taxonomy in which results are categorized.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub taxonomies: Option<Vec<ToolComponent>>,
    /// An array of threadFlowLocation objects cached at run level.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "threadFlowLocations")]
    #[builder(setter(strip_option), default)]
    pub thread_flow_locations: Option<Vec<ThreadFlowLocation>>,
    /// Information about the tool or tool pipeline that generated the results in this run. A run
    /// can only contain results produced by a single tool or tool pipeline. A run can aggregate
    /// results from multiple log files, as long as context around the tool run (tool command-line
    /// arguments and the like) is identical for all aggregated files.
    pub tool: Tool,
    /// The set of available translations of the localized data provided by the tool.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub translations: Option<Vec<ToolComponent>>,
    /// Specifies the revision in version control of the artifacts that were scanned.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "versionControlProvenance")]
    #[builder(setter(strip_option), default)]
    pub version_control_provenance: Option<Vec<VersionControlDetails>>,
    /// An array of request objects cached at run level.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webRequests")]
    #[builder(setter(strip_option), default)]
    pub web_requests: Option<Vec<WebRequest>>,
    /// An array of response objects cached at run level.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webResponses")]
    #[builder(setter(strip_option), default)]
    pub web_responses: Option<Vec<WebResponse>>,
}
/// Information that describes a run's identity and role within an engineering system process.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "runAutomationDetails")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct RunAutomationDetails {
    /// A stable, unique identifier for the equivalence class of runs to which this object's
    /// containing run object belongs in the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "correlationGuid")]
    #[builder(setter(strip_option), default)]
    pub correlation_guid: Option<String>,
    /// A description of the identity and role played within the engineering system by this
    /// object's containing run object.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub description: Option<Message>,
    /// A stable, unique identifier for this object's containing run object in the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub guid: Option<String>,
    /// A hierarchical string that uniquely identifies this object's containing run object.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub id: Option<String>,
    /// Key/value pairs that provide additional information about the run automation details.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// Defines locations of special significance to SARIF consumers.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "specialLocations")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct SpecialLocations {
    /// Provides a suggestion to SARIF consumers to display file paths relative to the specified
    /// location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "displayBase")]
    #[builder(setter(strip_option), default)]
    pub display_base: Option<ArtifactLocation>,
    /// Key/value pairs that provide additional information about the special locations.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// A call stack that is relevant to a result.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "stack")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Stack {
    /// An array of stack frames that represents a sequence of calls, rendered in reverse
    /// chronological order, that comprise the call stack.
    #[builder(default)]
    pub frames: Vec<StackFrame>,
    /// A message relevant to this call stack.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub message: Option<Message>,
    /// Key/value pairs that provide additional information about the stack.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// A function call within a stack trace.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "stackFrame")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct StackFrame {
    /// The location to which this stack frame refers.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub location: Option<Location>,
    /// The name of the module that contains the code of this stack frame.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub module: Option<String>,
    /// The parameters of the call that is executing.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub parameters: Option<Vec<String>>,
    /// Key/value pairs that provide additional information about the stack frame.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The thread identifier of the stack frame.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "threadId")]
    #[builder(setter(strip_option), default)]
    pub thread_id: Option<i64>,
}
/// A suppression that is relevant to a result.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "suppression")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Suppression {
    /// A stable, unique identifier for the suprression in the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub guid: Option<String>,
    /// A string representing the justification for the suppression.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub justification: Option<String>,
    /// A string that indicates where the suppression is persisted.
    pub kind: serde_json::Value,
    /// Identifies the location associated with the suppression.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub location: Option<Location>,
    /// Key/value pairs that provide additional information about the suppression.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A string that indicates the review status of the suppression.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub status: Option<serde_json::Value>,
}
/// Describes a sequence of code locations that specify a path through a single thread of execution
/// such as an operating system or fiber.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "threadFlow")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ThreadFlow {
    /// An string that uniquely identifies the threadFlow within the codeFlow in which it occurs.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub id: Option<String>,
    /// Values of relevant expressions at the start of the thread flow that remain constant.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "immutableState")]
    #[builder(setter(strip_option), default)]
    pub immutable_state: Option<
        ::std::collections::BTreeMap<String, MultiformatMessageString>,
    >,
    /// Values of relevant expressions at the start of the thread flow that may change during
    /// thread flow execution.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "initialState")]
    #[builder(setter(strip_option), default)]
    pub initial_state: Option<
        ::std::collections::BTreeMap<String, MultiformatMessageString>,
    >,
    /// A temporally ordered array of 'threadFlowLocation' objects, each of which describes a
    /// location visited by the tool while producing the result.
    #[builder(default)]
    pub locations: Vec<ThreadFlowLocation>,
    /// A message relevant to the thread flow.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub message: Option<Message>,
    /// Key/value pairs that provide additional information about the thread flow.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// A location visited by an analysis tool while simulating or monitoring the execution of a
/// program.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "threadFlowLocation")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ThreadFlowLocation {
    /// An integer representing the temporal order in which execution reached this location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "executionOrder")]
    #[builder(setter(strip_option), default)]
    pub execution_order: Option<i64>,
    /// The Coordinated Universal Time (UTC) date and time at which this location was executed.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "executionTimeUtc")]
    #[builder(setter(strip_option), default)]
    pub execution_time_utc: Option<String>,
    /// Specifies the importance of this location in understanding the code flow in which it
    /// occurs. The order from most to least important is "essential", "important", "unimportant".
    /// Default: "important".
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub importance: Option<serde_json::Value>,
    /// The index within the run threadFlowLocations array.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub index: Option<i64>,
    /// A set of distinct strings that categorize the thread flow location. Well-known kinds
    /// include 'acquire', 'release', 'enter', 'exit', 'call', 'return', 'branch', 'implicit',
    /// 'false', 'true', 'caution', 'danger', 'unknown', 'unreachable', 'taint', 'function',
    /// 'handler', 'lock', 'memory', 'resource', 'scope' and 'value'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub kinds: Option<Vec<String>>,
    /// The code location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub location: Option<Location>,
    /// The name of the module that contains the code that is executing.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub module: Option<String>,
    /// An integer representing a containment hierarchy within the thread flow.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "nestingLevel")]
    #[builder(setter(strip_option), default)]
    pub nesting_level: Option<i64>,
    /// Key/value pairs that provide additional information about the threadflow location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The call stack leading to this location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub stack: Option<Stack>,
    /// A dictionary, each of whose keys specifies a variable or expression, the associated value
    /// of which represents the variable or expression value. For an annotation of kind
    /// 'continuation', for example, this dictionary might hold the current assumed values of a set
    /// of global variables.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub state: Option<::std::collections::BTreeMap<String, MultiformatMessageString>>,
    /// An array of references to rule or taxonomy reporting descriptors that are applicable to the
    /// thread flow location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub taxa: Option<Vec<ReportingDescriptorReference>>,
    /// A web request associated with this thread flow location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webRequest")]
    #[builder(setter(strip_option), default)]
    pub web_request: Option<WebRequest>,
    /// A web response associated with this thread flow location.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "webResponse")]
    #[builder(setter(strip_option), default)]
    pub web_response: Option<WebResponse>,
}
/// The analysis tool that was run.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "tool")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Tool {
    /// The analysis tool that was run.
    pub driver: ToolComponent,
    /// Tool extensions that contributed to or reconfigured the analysis tool that was run.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub extensions: Option<Vec<ToolComponent>>,
    /// Key/value pairs that provide additional information about the tool.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// A component, such as a plug-in or the driver, of the analysis tool that was run.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "toolComponent")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ToolComponent {
    /// The component which is strongly associated with this component. For a translation, this
    /// refers to the component which has been translated. For an extension, this is the driver
    /// that provides the extension's plugin model.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "associatedComponent")]
    #[builder(setter(strip_option), default)]
    pub associated_component: Option<ToolComponentReference>,
    /// The kinds of data contained in this object.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub contents: Option<Vec<serde_json::Value>>,
    /// The binary version of the tool component's primary executable file expressed as four
    /// non-negative integers separated by a period (for operating systems that express file
    /// versions in this way).
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "dottedQuadFileVersion")]
    #[builder(setter(strip_option), default)]
    pub dotted_quad_file_version: Option<String>,
    /// The absolute URI from which the tool component can be downloaded.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "downloadUri")]
    #[builder(setter(strip_option), default)]
    pub download_uri: Option<String>,
    /// A comprehensive description of the tool component.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "fullDescription")]
    #[builder(setter(strip_option), default)]
    pub full_description: Option<MultiformatMessageString>,
    /// The name of the tool component along with its version and any other useful identifying
    /// information, such as its locale.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "fullName")]
    #[builder(setter(strip_option), default)]
    pub full_name: Option<String>,
    /// A dictionary, each of whose keys is a resource identifier and each of whose values is a
    /// multiformatMessageString object, which holds message strings in plain text and (optionally)
    /// Markdown format. The strings can include placeholders, which can be used to construct a
    /// message in combination with an arbitrary number of additional string arguments.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "globalMessageStrings")]
    #[builder(setter(strip_option), default)]
    pub global_message_strings: Option<
        ::std::collections::BTreeMap<String, MultiformatMessageString>,
    >,
    /// A unique identifier for the tool component in the form of a GUID.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub guid: Option<String>,
    /// The absolute URI at which information about this version of the tool component can be
    /// found.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "informationUri")]
    #[builder(setter(strip_option), default)]
    pub information_uri: Option<String>,
    /// Specifies whether this object contains a complete definition of the localizable and/or
    /// non-localizable data for this component, as opposed to including only data that is relevant
    /// to the results persisted to this log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "isComprehensive")]
    #[builder(setter(strip_option), default)]
    pub is_comprehensive: Option<bool>,
    /// The language of the messages emitted into the log file during this run (expressed as an ISO
    /// 639-1 two-letter lowercase language code) and an optional region (expressed as an ISO
    /// 3166-1 two-letter uppercase subculture code associated with a country or region). The
    /// casing is recommended but not required (in order for this data to conform to RFC5646).
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub language: Option<String>,
    /// The semantic version of the localized strings defined in this component; maintained by
    /// components that provide translations.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "localizedDataSemanticVersion")]
    #[builder(setter(strip_option), default)]
    pub localized_data_semantic_version: Option<String>,
    /// An array of the artifactLocation objects associated with the tool component.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub locations: Option<Vec<ArtifactLocation>>,
    /// The minimum value of localizedDataSemanticVersion required in translations consumed by this
    /// component; used by components that consume translations.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "minimumRequiredLocalizedDataSemanticVersion")]
    #[builder(setter(strip_option), default)]
    pub minimum_required_localized_data_semantic_version: Option<String>,
    /// The name of the tool component.
    pub name: String,
    /// An array of reportingDescriptor objects relevant to the notifications related to the
    /// configuration and runtime execution of the tool component.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub notifications: Option<Vec<ReportingDescriptor>>,
    /// The organization or company that produced the tool component.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub organization: Option<String>,
    /// A product suite to which the tool component belongs.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub product: Option<String>,
    /// A localizable string containing the name of the suite of products to which the tool
    /// component belongs.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "productSuite")]
    #[builder(setter(strip_option), default)]
    pub product_suite: Option<String>,
    /// Key/value pairs that provide additional information about the tool component.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A string specifying the UTC date (and optionally, the time) of the component's release.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "releaseDateUtc")]
    #[builder(setter(strip_option), default)]
    pub release_date_utc: Option<String>,
    /// An array of reportingDescriptor objects relevant to the analysis performed by the tool
    /// component.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub rules: Option<Vec<ReportingDescriptor>>,
    /// The tool component version in the format specified by Semantic Versioning 2.0.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "semanticVersion")]
    #[builder(setter(strip_option), default)]
    pub semantic_version: Option<String>,
    /// A brief description of the tool component.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "shortDescription")]
    #[builder(setter(strip_option), default)]
    pub short_description: Option<MultiformatMessageString>,
    /// An array of toolComponentReference objects to declare the taxonomies supported by the tool
    /// component.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "supportedTaxonomies")]
    #[builder(setter(strip_option), default)]
    pub supported_taxonomies: Option<Vec<ToolComponentReference>>,
    /// An array of reportingDescriptor objects relevant to the definitions of both standalone and
    /// tool-defined taxonomies.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub taxa: Option<Vec<ReportingDescriptor>>,
    /// Translation metadata, required for a translation, not populated by other component types.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "translationMetadata")]
    #[builder(setter(strip_option), default)]
    pub translation_metadata: Option<TranslationMetadata>,
    /// The tool component version, in whatever format the component natively provides.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub version: Option<String>,
}
/// Identifies a particular toolComponent object, either the driver or an extension.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "toolComponentReference")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct ToolComponentReference {
    /// The 'guid' property of the referenced toolComponent.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub guid: Option<String>,
    /// An index into the referenced toolComponent in tool.extensions.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub index: Option<i64>,
    /// The 'name' property of the referenced toolComponent.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub name: Option<String>,
    /// Key/value pairs that provide additional information about the toolComponentReference.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
}
/// Provides additional metadata related to translation.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "translationMetadata")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct TranslationMetadata {
    /// The absolute URI from which the translation metadata can be downloaded.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "downloadUri")]
    #[builder(setter(strip_option), default)]
    pub download_uri: Option<String>,
    /// A comprehensive description of the translation metadata.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "fullDescription")]
    #[builder(setter(strip_option), default)]
    pub full_description: Option<MultiformatMessageString>,
    /// The full name associated with the translation metadata.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "fullName")]
    #[builder(setter(strip_option), default)]
    pub full_name: Option<String>,
    /// The absolute URI from which information related to the translation metadata can be
    /// downloaded.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "informationUri")]
    #[builder(setter(strip_option), default)]
    pub information_uri: Option<String>,
    /// The name associated with the translation metadata.
    pub name: String,
    /// Key/value pairs that provide additional information about the translation metadata.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// A brief description of the translation metadata.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "shortDescription")]
    #[builder(setter(strip_option), default)]
    pub short_description: Option<MultiformatMessageString>,
}
/// Specifies the information necessary to retrieve a desired revision from a version control
/// system.
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[serde(rename = "versionControlDetails")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct VersionControlDetails {
    /// A Coordinated Universal Time (UTC) date and time that can be used to synchronize an
    /// enlistment to the state of the repository at that time.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "asOfTimeUtc")]
    #[builder(setter(strip_option), default)]
    pub as_of_time_utc: Option<String>,
    /// The name of a branch containing the revision.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub branch: Option<String>,
    /// The location in the local file system to which the root of the repository was mapped at the
    /// time of the analysis.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "mappedTo")]
    #[builder(setter(strip_option), default)]
    pub mapped_to: Option<ArtifactLocation>,
    /// Key/value pairs that provide additional information about the version control details.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The absolute URI of the repository.
    #[serde(rename = "repositoryUri")]
    pub repository_uri: String,
    /// A string that uniquely and permanently identifies the revision within the repository.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "revisionId")]
    #[builder(setter(strip_option), default)]
    pub revision_id: Option<String>,
    /// A tag that has been applied to the revision.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "revisionTag")]
    #[builder(setter(strip_option), default)]
    pub revision_tag: Option<String>,
}
/// Describes an HTTP request.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "webRequest")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct WebRequest {
    /// The body of the request.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub body: Option<ArtifactContent>,
    /// The request headers.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub headers: Option<::std::collections::BTreeMap<String, String>>,
    /// The index within the run.webRequests array of the request object associated with this
    /// result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub index: Option<i64>,
    /// The HTTP method. Well-known values are 'GET', 'PUT', 'POST', 'DELETE', 'PATCH', 'HEAD',
    /// 'OPTIONS', 'TRACE', 'CONNECT'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub method: Option<String>,
    /// The request parameters.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub parameters: Option<::std::collections::BTreeMap<String, String>>,
    /// Key/value pairs that provide additional information about the request.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The request protocol. Example: 'http'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub protocol: Option<String>,
    /// The target of the request.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub target: Option<String>,
    /// The request version. Example: '1.1'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub version: Option<String>,
}
/// Describes the response to an HTTP request.
#[derive(Clone, PartialEq, Debug, Default, Deserialize, Serialize)]
#[serde(rename = "webResponse")]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct WebResponse {
    /// The body of the response.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub body: Option<ArtifactContent>,
    /// The response headers.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub headers: Option<::std::collections::BTreeMap<String, String>>,
    /// The index within the run.webResponses array of the response object associated with this
    /// result.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub index: Option<i64>,
    /// Specifies whether a response was received from the server.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "noResponseReceived")]
    #[builder(setter(strip_option), default)]
    pub no_response_received: Option<bool>,
    /// Key/value pairs that provide additional information about the response.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The response protocol. Example: 'http'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub protocol: Option<String>,
    /// The response reason. Example: 'Not found'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "reasonPhrase")]
    #[builder(setter(strip_option), default)]
    pub reason_phrase: Option<String>,
    /// The response status code. Example: 451.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "statusCode")]
    #[builder(setter(strip_option), default)]
    pub status_code: Option<i64>,
    /// The response version. Example: '1.1'.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub version: Option<String>,
}
#[derive(Clone, PartialEq, Debug, Deserialize, Serialize)]
#[derive(TypedBuilder)]
#[builder(field_defaults(setter(into)))]
pub struct Sarif {
    /// The URI of the JSON schema corresponding to the version.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "$schema")]
    #[builder(setter(strip_option), default)]
    pub schema: Option<String>,
    /// References to external property files that share data between runs.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[serde(rename = "inlineExternalProperties")]
    #[builder(setter(strip_option), default)]
    pub inline_external_properties: Option<Vec<ExternalProperties>>,
    /// Key/value pairs that provide additional information about the log file.
    #[serde(skip_serializing_if = "Option::is_none")]
    #[builder(setter(strip_option), default)]
    pub properties: Option<PropertyBag>,
    /// The set of runs contained in this log file.
    #[builder(default)]
    pub runs: Vec<Run>,
    /// The SARIF format version of this log file.
    pub version: serde_json::Value,
}
