import { useQuery } from "@tanstack/react-query";
import { useNavigate } from "@tanstack/react-router";
import { extensionsQuery } from "../api/extensions";
import type { Project } from "../api/types";
import { SectionMenu } from "../ui/SectionMenu";

/** The Project sections. Documents appears only while the Gateway reports the documents extension enabled. */
export function ProjectMenu({
    project,
    documents,
    dependencies = false,
    beforeLeave = () => true,
}: {
    project: Project;
    documents: boolean;
    dependencies?: boolean;
    beforeLeave?: () => boolean;
}) {
    const navigate = useNavigate();
    const documentsEnabled = useQuery(extensionsQuery).data?.documents === true;
    return (
        <SectionMenu
            responsive
            className="md:w-[18ch]!"
            label="Project navigation"
            ariaLabel="Project sections"
            idPrefix={`project-${project.id}`}
            items={[
                { id: "overview", label: "Overview", testId: "project-overview" },
                { id: "dependencies", label: "Dependencies", testId: "project-dependencies" },
                ...(documentsEnabled
                    ? [{ id: "documents", label: "Documents", testId: "project-documents" }]
                    : []),
            ]}
            selected={documents ? "documents" : dependencies ? "dependencies" : "overview"}
            onSelect={(section) => {
                if (!beforeLeave()) return;
                if (section === "dependencies") {
                    void navigate({
                        to: "/projects/$id/dependencies",
                        params: { id: String(project.id) },
                    });
                    return;
                }
                if (section === "documents")
                    void navigate({
                        to: "/projects/$id/documents",
                        params: { id: String(project.id) },
                        search: {},
                    });
                else
                    void navigate({
                        to: "/$section/$id",
                        params: { section: "projects", id: String(project.id) },
                    });
            }}
        />
    );
}
