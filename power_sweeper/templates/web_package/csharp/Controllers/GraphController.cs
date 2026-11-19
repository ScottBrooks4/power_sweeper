using System.Net;
using System.Text;
using System.Text.Json;

public static class GraphController
{
    public static bool TryHandle(HttpListenerContext ctx, string root, string path)
    {
        if (!path.StartsWith("/api/graph", StringComparison.Ordinal))
        {
            return false;
        }

        Dictionary<string, JsonElement>? body = null;
        if (ctx.Request.HttpMethod is "POST" or "PUT" or "PATCH")
        {
            using var reader = new StreamReader(ctx.Request.InputStream, ctx.Request.ContentEncoding);
            var raw = reader.ReadToEnd();
            if (!string.IsNullOrWhiteSpace(raw))
            {
                using var doc = JsonDocument.Parse(raw);
                body = new Dictionary<string, JsonElement>(StringComparer.OrdinalIgnoreCase);
                foreach (var prop in doc.RootElement.EnumerateObject())
                {
                    body[prop.Name] = prop.Value.Clone();
                }
            }
        }

        var resource = "";
        if (body != null && body.TryGetValue("resource", out var r))
        {
            resource = r.GetString() ?? "";
        }
        if (string.IsNullOrEmpty(resource))
        {
            var bits = path.TrimEnd('/').Split('/');
            resource = bits.Length > 0 ? bits[^1] : "status";
            if (resource is "graph")
            {
                resource = "status";
            }
        }
        if (string.IsNullOrEmpty(resource))
        {
            resource = ctx.Request.QueryString["resource"] ?? "status";
        }

        object result = resource switch
        {
            "status" or "me" => GraphModel.Status(root),
            "mail" or "sendMail" => GraphModel.SendMail(root, body),
            "calendar" => GraphModel.Calendar(root, body),
            "files" or "drive" => GraphModel.Files(root, body),
            "serviceAccount" or "service_account" => GraphModel.ServiceAccount(root, body),
            _ => new { ok = false, error = "unknown graph resource" },
        };

        var json = JsonSerializer.Serialize(result);
        var bytes = Encoding.UTF8.GetBytes(json);
        ctx.Response.StatusCode = 200;
        ctx.Response.ContentType = "application/json; charset=utf-8";
        ctx.Response.ContentLength64 = bytes.Length;
        ctx.Response.OutputStream.Write(bytes);
        ctx.Response.Close();
        return true;
    }
}
